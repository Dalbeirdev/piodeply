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
            // UTF-8 WITH BOM, not the default BOM-less flavour: Windows
            // PowerShell 5.1 reads a BOM-less file as ANSI, where a UTF-8
            // em-dash mis-decodes into a smart quote that TERMINATES a
            // string early - a parse error, exit 1, script never runs.
            // That single character stranded the entire fleet's updates.
            await File.WriteAllTextAsync(
                script, BuildSwapScript(staging, installDir, root),
                new System.Text.UTF8Encoding(encoderShouldEmitUTF8Identifier: true), ct);

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

    /// <summary>Removes this agent from the machine, on an operator's explicit
    /// request from the portal. The same detached-helper pattern as an update
    /// (a service cannot delete itself), but one-way: service deleted, install
    /// dir and state removed. Software the agent installed stays.</summary>
    /// <returns>True when the helper was scheduled — the caller should then
    /// let the service stop.</returns>
    public bool Uninstall()
    {
        var root = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "PioDeploy", "update");
        var installDir = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);

        try
        {
            Directory.CreateDirectory(root);

            var script = Path.Combine(root, "uninstall-agent.ps1");
            // BOM for the same reason as the swap script: PowerShell 5.1
            // treats a BOM-less file as ANSI and can misparse it fatally.
            File.WriteAllText(script, BuildUninstallScript(installDir),
                new System.Text.UTF8Encoding(encoderShouldEmitUTF8Identifier: true));

            LaunchDetached(script);
            _logger.LogInformation("Uninstall requested from the server; the service will now stop and be removed.");
            return true;
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Uninstall could not be staged; the agent stays installed.");
            return false;
        }
    }

    /// <summary>The uninstall helper. Logs to the Windows temp dir — every
    /// PioDeploy directory is gone by the time it finishes. Internal so tests
    /// can pin what the script must (and must not) do.</summary>
    internal static string BuildUninstallScript(string installDir)
    {
        return $$"""
$ErrorActionPreference = 'SilentlyContinue'
$svc = 'PioDeployAgent'
$install = '{{installDir}}'
$state   = Join-Path $env:ProgramData 'PioDeploy'
$log     = Join-Path $env:windir 'Temp\piodeploy-uninstall.log'
function Log($m) { "{0}  {1}" -f (Get-Date -Format 's'), $m | Out-File $log -Append -Encoding utf8 }

Log "Uninstall helper started (waiting for the agent to exit)"
Start-Sleep -Seconds 8

# Kill the watchdog first, or it restarts the service we are removing.
# cmd.exe /c everywhere: a detached helper must never emit host output,
# and a missing task must be a no-op, never a script-killing error.
cmd.exe /c "schtasks /Delete /TN PioDeployAgentWatchdog /F >nul 2>&1"
# And the per-user tray helper: both tasks, then any running copy - the
# keeper would otherwise resurrect the icon on a machine with no agent.
cmd.exe /c "schtasks /Delete /TN PioDeployAgentTray /F >nul 2>&1"
cmd.exe /c "schtasks /Delete /TN PioDeployAgentTrayKeeper /F >nul 2>&1"
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -like '*pio-tray*' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

Log "Stopping and deleting $svc"
Stop-Service $svc -Force
Start-Sleep -Seconds 3
sc.exe delete $svc | Out-Null

Log "Removing $install"
Remove-Item $install -Recurse -Force

Log "Removing $state"
Remove-Item $state -Recurse -Force

cmd.exe /c "schtasks /Delete /TN PioDeployAgentSelfUpdate /F >nul 2>&1"
Log "Agent removed"
# Last: unregister the task running this very script.
cmd.exe /c "schtasks /Delete /TN PioDeployAgentUninstall /F >nul 2>&1"
""";
    }

    /// <summary>The helper. Backs up, swaps, verifies, and rolls back on
    /// failure — all after this process has exited. Internal so tests can
    /// pin what the script must (and must not) do.</summary>
    internal static string BuildSwapScript(string staging, string installDir, string root)
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

# Written before anything else so the log always proves the helper ran,
# even if a later step throws - the previous launch mechanism produced no
# log at all, which is what made the loop invisible.
Log "Helper started (waiting for the agent process to exit)"
# The agent stops itself right after scheduling us; give the SCM a moment
# so Stop-Service below is a no-op and the executable is unlocked.
Start-Sleep -Seconds 8

# Hold the watchdog off for the swap: it restarts a stopped service every
# two minutes, which would relaunch the old agent mid-copy. Disabled now,
# re-enabled in both the success and rollback paths so the machine is never
# left without its keep-alive. Via cmd.exe /c on purpose, twice over: the
# helper must never emit host output (a stdout write once killed a helper
# holding pipes to its dead parent), and under ErrorActionPreference=Stop
# PowerShell 5.1 turns a native command's redirected stderr into a
# TERMINATING error - on a machine with no watchdog task (anything
# enrolled before 1.4.9), a bare schtasks call here killed the helper
# right after "Helper started", before the try block. cmd absorbs both
# streams and the exit code, so a missing task is simply a no-op.
cmd.exe /c "schtasks /Change /TN PioDeployAgentWatchdog /DISABLE >nul 2>&1"

try {
    Log "Stopping $svc"
    Stop-Service $svc -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 3

    if (Test-Path $backup) { Remove-Item $backup -Recurse -Force }
    Log "Backing up current install"
    Copy-Item $install $backup -Recurse -Force

    Log "Copying new files (keeping this machine's appsettings.json)"
    # The bundle's appsettings.json is a placeholder template for fresh
    # enrollments; the installed one holds this machine's ServerUrl and API
    # key. Overwriting it disconnects the agent from the server.
    Get-ChildItem $staging -Exclude 'appsettings*.json' |
        Copy-Item -Destination $install -Recurse -Force

    Log "Starting $svc"
    Start-Service $svc
    Start-Sleep -Seconds 8

    if ((Get-Service $svc).Status -ne 'Running') {
        throw "service did not start after update"
    }
    Log "Update applied and service running"
    Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue
    cmd.exe /c "schtasks /Change /TN PioDeployAgentWatchdog /ENABLE >nul 2>&1"
}
catch {
    cmd.exe /c "schtasks /Change /TN PioDeployAgentWatchdog /ENABLE >nul 2>&1"
    Log "FAILED: $($_.Exception.Message) - rolling back"
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

    /// <summary>Starts the helper as a plain detached child process and
    /// PROVES it is alive before reporting success.
    ///
    /// History, because this launch has now failed in the field three ways:
    /// UseShellExecute=true silently does nothing in a service's session 0
    /// (no shell to execute through); a schtasks one-shot task proved just
    /// as silent (/TR quoting is fragile and its errors were swallowed);
    /// and redirecting the helper's std streams to THIS process wedged the
    /// swap mid-flight — the helper outlives us by design, so once we exit
    /// its pipes point at a dead parent, and its first stdout write (a
    /// schtasks "SUCCESS" line) killed powershell after the service was
    /// already stopped and the watchdog already disabled: machine dark.
    /// A detached child must NEVER hold pipes to a mortal parent — so no
    /// redirects here; the helper reports through its log file instead.
    ///
    /// UseShellExecute=false is a raw CreateProcess: no shell, no Task
    /// Scheduler, works in session 0 (proven by a SYSTEM-context probe),
    /// and Windows does not kill child processes when the parent exits.
    /// The helper sleeps 8s first, so it comfortably outlives our
    /// shutdown. Script diagnosability lives in ValidateScript, which runs
    /// BEFORE launch in a process that never outlives us.</summary>
    private void LaunchDetached(string scriptPath)
    {
        ValidateScript(scriptPath);

        var p = System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = $"-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"{scriptPath}\"",
            UseShellExecute = false,
            CreateNoWindow = true,
            // NOT the script's own directory: the uninstall script deletes
            // that tree, and Windows cannot remove a live process's working
            // directory — it would leave an empty ProgramData\PioDeploy
            // skeleton on every "uninstalled" machine.
            WorkingDirectory = Path.GetTempPath(),
        }) ?? throw new InvalidOperationException("Process.Start returned null for the update helper.");

        // A helper that dies instantly (bad powershell path, corrupt script)
        // must fail the staging attempt NOW — stopping the service on the
        // strength of a dead helper is exactly the offline loop this code
        // exists to prevent.
        if (p.WaitForExit(2_000))
        {
            throw new InvalidOperationException(
                $"Update helper exited immediately (code {p.ExitCode}) instead of waiting for the service to stop.");
        }

        _logger.LogInformation("Update helper running as PID {Pid}.", p.Id);
    }

    /// <summary>Parses the helper script with THE SAME powershell.exe that
    /// will run it, before anything is launched detached. The fleet was once
    /// stranded for days by a helper dying with a bare "exit 1": Windows
    /// PowerShell 5.1 misparsed a mis-encoded quote character, and the parse
    /// error was never seen because the helper's stderr went nowhere. This
    /// validator is a short-lived child that cannot outlive us, so capturing
    /// ITS streams is safe — any parse error surfaces here, with the message,
    /// while the service is still healthy and nothing has been stopped.</summary>
    private static void ValidateScript(string scriptPath)
    {
        const string check =
            "$e=$null;[void][System.Management.Automation.Language.Parser]::ParseFile($env:PIO_CHECK,[ref]$null,[ref]$e);" +
            "if($e.Count){$e|ForEach-Object{[Console]::Error.WriteLine($_.Message)};exit 3};exit 0";

        var psi = new System.Diagnostics.ProcessStartInfo
        {
            FileName = "powershell.exe",
            Arguments = $"-NoProfile -NonInteractive -Command \"{check}\"",
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        // The path rides in an environment variable so no quoting scheme can
        // mangle it inside the doubly-nested command string.
        psi.Environment["PIO_CHECK"] = scriptPath;

        using var p = System.Diagnostics.Process.Start(psi)
            ?? throw new InvalidOperationException("Process.Start returned null for the script validator.");

        var stderr = p.StandardError.ReadToEnd();
        p.StandardOutput.ReadToEnd();
        if (!p.WaitForExit(15_000))
        {
            try { p.Kill(entireProcessTree: true); } catch { /* already gone */ }
            throw new InvalidOperationException("Script validation timed out.");
        }

        if (p.ExitCode != 0)
        {
            throw new InvalidOperationException(
                $"Helper script failed powershell parse validation (exit {p.ExitCode}): {stderr.Trim()}");
        }
    }
}
