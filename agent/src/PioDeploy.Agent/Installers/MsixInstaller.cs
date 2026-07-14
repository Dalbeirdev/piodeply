using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Installers;

public sealed class MsixInstaller : BinaryInstallerBase
{
    public MsixInstaller(IProcessRunner processRunner, IPackageDownloader downloader, IChecksumVerifier checksum, TimeSpan? timeout = null)
        : base(processRunner, downloader, checksum, timeout)
    {
    }

    public override bool Supports(string installerType) => installerType == "msix";

    protected override async Task<InstallResult> RunVerifiedAsync(JobPayload job, string filePath, CancellationToken ct)
    {
        var result = await ProcessRunner.RunAsync("powershell", [
            "-NoProfile", "-NonInteractive", "-Command",
            "Add-AppxPackage", "-Path", filePath,
        ], Timeout, ct);

        return MapExitCode(result, "Add-AppxPackage");
    }
}
