using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Installers;

/// <summary>Runs a checksum-verified PowerShell install script.</summary>
public sealed class PowerShellInstaller : BinaryInstallerBase
{
    public PowerShellInstaller(IProcessRunner processRunner, IPackageDownloader downloader, IChecksumVerifier checksum, TimeSpan? timeout = null)
        : base(processRunner, downloader, checksum, timeout)
    {
    }

    public override bool Supports(string installerType) => installerType == "powershell";

    protected override async Task<InstallResult> RunVerifiedAsync(JobPayload job, string filePath, CancellationToken ct)
    {
        List<string> arguments = ["-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", filePath];
        arguments.AddRange(SplitArgs(job.SilentArgs));

        var result = await ProcessRunner.RunAsync("powershell", arguments, Timeout, ct);

        return MapExitCode(result, "powershell");
    }
}
