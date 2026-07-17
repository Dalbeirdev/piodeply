using System.IO.Compression;
using Microsoft.Extensions.Logging;

namespace PioDeploy.Agent.Services;

/// <summary>Replaces the running agent with a newer build the server offered.
///
/// A service cannot overwrite its own running executable, so the swap is done
/// by a detached PowerShell helper that outlives this process: it stops the
/// service, backs up the current install, copies the new files in, and starts
/// the service again — rolling the backup back if the new build does not come
/// up. So a botched update self-heals rather than bricking the machine, which
/// is the whole risk of auto-update.</summary>
public sealed class SelfUpdater
{
    private readonly IApiClient _api;
    private readonly ILogger<SelfUpdater> _logger;

    public SelfUpdater(IApiClient api, ILogger<SelfUpdater> logger)
    {
        _api = api;
        _logger = logger;
    }

    /// <returns>True when a swap was staged and the helper launched — the
    /// caller should then let the service stop.</returns>
    public async Task<bool> UpdateAsync(string bundleUrl, string version, CancellationToken ct)
    {
        var root = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "PioDeploy", "update");

        var zipPath = Path.Combine(root, "PioDeployAgent.zip");
        var staging = Path.Combine(root, "staging");
        var installDir = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);

        try
        {
            Directory.CreateDirectory(root);
            if (Directory.Exists(staging)) Directory.Delete(staging, recursive: true);

            _logger.LogInformation("Self-update to {Version}: downloading bundle.", version);
            if (!await _api.DownloadBundleAsync(bundleUrl, zipPath, ct))
            {
                _logger.LogWarning("Self-update aborted: bundle download failed.");
                return false;
            }

            ZipFile.ExtractToDirectory(zipPath, staging, overwriteFiles: true);

            var stagedExe = Path.Combine(staging, "PioDeployAgent.exe");
            if (!File.Exists(stagedExe))
            {
                _logger.LogWarning("Self-update aborted: the bundle has no PioDeployAgent.exe.");
                return false;
            }

            var script = Path.Combine(root, "apply-update.ps1");
            await File.WriteAllTextAsync(script, BuildSwapScript(staging, installDir, root), ct);

            LaunchDetached(script);
            _logger.LogInformation("Self-update helper launched; the service will now stop to be replaced.");
            return true;
        }
        catch (Exception ex)
        {
            // A failure here leaves the running agent untouched — the swap only
            // happens in the detached helper, which we never reached.
            _logger.LogWarning(ex, "Self-update could not be staged; staying on the current version.");
            return false;
        }
    }

    /// <summary>The helper. Backs up, swaps, verifies, and rolls back on
    /// failure — all after this process has exited.</summary>
    private static string BuildSwapScript(string staging, string installDir, string root)
    {
        var backup = Path.Combine(root, "backup");
        var log = Path.Combine(root, "self-update.log");

        // Single-quoted PowerShell literals; these paths are ours, not user
        // input, but keep them quoted regardless.
        return $$"""
$ErrorActionPreference = 'Stop'
$svc = 'PioDeployAgent'
$staging = '{{staging}}'
$install = '{{installDir}}'
$backup  = '{{backup}}'
$log     = '{{log}}'
function Log($m) { "{0}  {1}" -f (Get-Date -Format 's'), $m | Out-File $log -Append -Encoding utf8 }

try {
    Log "Stopping $svc"
    Stop-Service $svc -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 3

    if (Test-Path $backup) { Remove-Item $backup -Recurse -Force }
    Log "Backing up current install"
    Copy-Item $install $backup -Recurse -Force

    Log "Copying new files"
    Copy-Item (Join-Path $staging '*') $install -Recurse -Force

    Log "Starting $svc"
    Start-Service $svc
    Start-Sleep -Seconds 8

    if ((Get-Service $svc).Status -ne 'Running') {
        throw "service did not start after update"
    }
    Log "Update applied and service running"
    Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue
}
catch {
    Log "FAILED: $($_.Exception.Message) — rolling back"
    try {
        Stop-Service $svc -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 2
        if (Test-Path $backup) { Copy-Item (Join-Path $backup '*') $install -Recurse -Force }
        Start-Service $svc
        Log "Rolled back to previous version"
    } catch { Log "ROLLBACK FAILED: $($_.Exception.Message)" }
}
""";
    }

    private static void LaunchDetached(string scriptPath)
    {
        // Runs as the same SYSTEM account this service runs under, and outlives
        // it: UseShellExecute + a new process group so stopping the service
        // does not take the helper with it.
        var psi = new System.Diagnostics.ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = $"-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"{scriptPath}\"",
            UseShellExecute = true,
            CreateNoWindow = true,
            WindowStyle = System.Diagnostics.ProcessWindowStyle.Hidden,
        };

        System.Diagnostics.Process.Start(psi);
    }
}
