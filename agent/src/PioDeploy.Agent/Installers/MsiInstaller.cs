using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Installers;

public sealed class MsiInstaller : BinaryInstallerBase
{
    private const int RebootRequired = 3010;
    private const int RebootInitiated = 1641;

    public MsiInstaller(IProcessRunner processRunner, IPackageDownloader downloader, IChecksumVerifier checksum, TimeSpan? timeout = null)
        : base(processRunner, downloader, checksum, timeout)
    {
    }

    public override bool Supports(string installerType) => installerType == "msi";

    protected override async Task<InstallResult> RunVerifiedAsync(JobPayload job, string filePath, CancellationToken ct)
    {
        var arguments = BuildArguments(job.Action, filePath, job.SilentArgs, job.UninstallArgs);

        var result = await ProcessRunner.RunAsync("msiexec", arguments, Timeout, ct);

        return MapExitCode(result, "msiexec", RebootRequired, RebootInitiated);
    }

    public static IReadOnlyList<string> BuildArguments(string action, string filePath, string? silentArgs, string? uninstallArgs)
    {
        List<string> arguments = action == "uninstall"
            ? ["/x", filePath, "/qn", "/norestart"]
            : ["/i", filePath, "/qn", "/norestart"];

        arguments.AddRange(SplitArgs(action == "uninstall" ? uninstallArgs : silentArgs));

        return arguments;
    }
}
