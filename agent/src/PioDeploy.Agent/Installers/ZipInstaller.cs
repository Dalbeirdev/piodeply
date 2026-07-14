using System.IO.Compression;
using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Installers;

/// <summary>ZIP + portable apps: extract the verified archive into the
/// managed apps directory. Rollback: a failed/partial extraction is
/// removed so no half-deployed app is left behind.</summary>
public sealed class ZipInstaller : BinaryInstallerBase
{
    private readonly string _appsRoot;

    public ZipInstaller(
        IProcessRunner processRunner,
        IPackageDownloader downloader,
        IChecksumVerifier checksum,
        string? appsRoot = null,
        TimeSpan? timeout = null)
        : base(processRunner, downloader, checksum, timeout)
    {
        _appsRoot = appsRoot ?? Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "PioDeploy", "Apps");
    }

    public override bool Supports(string installerType) => installerType is "zip" or "portable";

    protected override Task<InstallResult> RunVerifiedAsync(JobPayload job, string filePath, CancellationToken ct)
    {
        var safeName = string.Join("_", (job.Package ?? $"job-{job.JobId}").Split(Path.GetInvalidFileNameChars()));
        var target = Path.Combine(_appsRoot, safeName);

        try
        {
            if (Directory.Exists(target))
            {
                Directory.Delete(target, recursive: true); // replace previous deployment
            }
            Directory.CreateDirectory(target);

            ZipFile.ExtractToDirectory(filePath, target, overwriteFiles: true);

            return Task.FromResult(InstallResult.Ok(0, $"Extracted to {target}"));
        }
        catch (Exception ex)
        {
            // Rollback: never leave a partial extraction behind.
            try
            {
                if (Directory.Exists(target))
                {
                    Directory.Delete(target, recursive: true);
                }
            }
            catch
            {
                // best effort
            }

            return Task.FromResult(InstallResult.Fail($"Extraction failed (rolled back): {ex.Message}"));
        }
    }
}
