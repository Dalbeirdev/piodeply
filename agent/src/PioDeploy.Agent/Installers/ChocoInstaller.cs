using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Installers;

public sealed class ChocoInstaller : IInstaller
{
    private readonly IProcessRunner _processRunner;
    private readonly TimeSpan _timeout;

    public ChocoInstaller(IProcessRunner processRunner, TimeSpan? timeout = null)
    {
        _processRunner = processRunner;
        _timeout = timeout ?? TimeSpan.FromMinutes(30);
    }

    public bool Supports(string installerType) => installerType == "choco";

    public async Task<InstallResult> ExecuteAsync(JobPayload job, CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(job.ChocoId))
        {
            return InstallResult.Fail("Job has no Chocolatey id.");
        }

        var arguments = BuildArguments(job.Action, job.ChocoId, job.Version);
        if (arguments is null)
        {
            return InstallResult.Fail($"Action '{job.Action}' is not supported for Chocolatey packages.");
        }

        var result = await _processRunner.RunAsync("choco", arguments, _timeout, ct);

        if (result.TimedOut)
        {
            return InstallResult.Fail("choco timed out.", result.ExitCode, result.Output);
        }

        // 0 = ok, 1641/3010 = success + reboot initiated/required
        return result.ExitCode is 0 or 1641 or 3010
            ? InstallResult.Ok(result.ExitCode, result.Output)
            : InstallResult.Fail($"choco exited with {result.ExitCode}.", result.ExitCode, result.Output);
    }

    public static IReadOnlyList<string>? BuildArguments(string action, string chocoId, string? version)
    {
        List<string>? arguments = action switch
        {
            "install" or "repair" => ["install", chocoId, "-y", "--no-progress"],
            "update" => ["upgrade", chocoId, "-y", "--no-progress"],
            "rollback" => version is null ? null : ["install", chocoId, "-y", "--no-progress", "--version", version, "--force"],
            "uninstall" => ["uninstall", chocoId, "-y"],
            _ => null,
        };

        if (arguments is not null && action is "install" && version is not null)
        {
            arguments.AddRange(["--version", version]);
        }

        return arguments;
    }
}
