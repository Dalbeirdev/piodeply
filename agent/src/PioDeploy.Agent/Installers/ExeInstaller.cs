using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Installers;

public sealed class ExeInstaller : BinaryInstallerBase
{
    public ExeInstaller(IProcessRunner processRunner, IPackageDownloader downloader, IChecksumVerifier checksum, TimeSpan? timeout = null)
        : base(processRunner, downloader, checksum, timeout)
    {
    }

    public override bool Supports(string installerType) => installerType == "exe";

    protected override async Task<InstallResult> RunVerifiedAsync(JobPayload job, string filePath, CancellationToken ct)
    {
        var result = await ProcessRunner.RunAsync(filePath, SplitArgs(job.SilentArgs), Timeout, ct);

        // 3010: common "success, reboot required" convention for setup EXEs.
        return MapExitCode(result, Path.GetFileName(filePath), 3010);
    }
}
