using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Installers;

/// <summary>Shared download-and-verify flow for installers that execute a
/// fetched binary (MSI/EXE/ZIP/MSIX/portable/PowerShell). The checksum gate
/// is mandatory: a payload that does not match its catalogue SHA-256 is
/// never executed.</summary>
public abstract class BinaryInstallerBase : IInstaller
{
    protected readonly IProcessRunner ProcessRunner;
    protected readonly IPackageDownloader Downloader;
    protected readonly IChecksumVerifier Checksum;
    protected readonly TimeSpan Timeout;

    protected BinaryInstallerBase(
        IProcessRunner processRunner,
        IPackageDownloader downloader,
        IChecksumVerifier checksum,
        TimeSpan? timeout = null)
    {
        ProcessRunner = processRunner;
        Downloader = downloader;
        Checksum = checksum;
        Timeout = timeout ?? TimeSpan.FromMinutes(30);
    }

    public abstract bool Supports(string installerType);

    public async Task<InstallResult> ExecuteAsync(JobPayload job, CancellationToken ct)
    {
        if (job.Action is "uninstall" && this is not MsiInstaller)
        {
            return InstallResult.Fail(
                $"Uninstall is not supported for {job.InstallerType} packages yet — use winget/choco/MSI packages for managed removal.");
        }

        if (string.IsNullOrWhiteSpace(job.InstallerUrl))
        {
            return InstallResult.Fail("Job has no installer URL.");
        }

        if (string.IsNullOrWhiteSpace(job.Sha256))
        {
            return InstallResult.Fail("Job has no SHA-256 checksum; refusing to execute an unverifiable payload.");
        }

        try
        {
            var path = await Downloader.DownloadAsync(job.JobId, job.InstallerUrl, ct);

            if (! await Checksum.MatchesSha256Async(path, job.Sha256, ct))
            {
                return InstallResult.Fail(
                    "Checksum mismatch: downloaded file does not match the catalogue SHA-256. Refusing to execute.");
            }

            return await RunVerifiedAsync(job, path, ct);
        }
        catch (HttpRequestException ex)
        {
            return InstallResult.Fail($"Download failed: {ex.Message}");
        }
        finally
        {
            Downloader.Cleanup(job.JobId);
        }
    }

    /// <summary>Runs after the payload passed checksum verification.</summary>
    protected abstract Task<InstallResult> RunVerifiedAsync(JobPayload job, string filePath, CancellationToken ct);

    protected static IReadOnlyList<string> SplitArgs(string? args)
        => string.IsNullOrWhiteSpace(args)
            ? []
            : args.Split(' ', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);

    protected static InstallResult MapExitCode(ProcessResult result, string tool, params int[] extraSuccessCodes)
    {
        if (result.TimedOut)
        {
            return InstallResult.Fail($"{tool} timed out.", result.ExitCode, result.Output);
        }

        return result.ExitCode == 0 || extraSuccessCodes.Contains(result.ExitCode)
            ? InstallResult.Ok(result.ExitCode, result.Output)
            : InstallResult.Fail($"{tool} exited with {result.ExitCode}.", result.ExitCode, result.Output);
    }
}
