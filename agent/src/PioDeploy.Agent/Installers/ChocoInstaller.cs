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
        if (result.ExitCode is 0 or 1641 or 3010)
        {
            return InstallResult.Ok(result.ExitCode, result.Output);
        }

        // Removal is about the end state: a package that was never there is
        // already "uninstalled". Chocolatey has no exit code for it — it
        // says so in words — so this reads the words it actually prints.
        if (job.Action == "uninstall" && NotInstalled(result.Output))
        {
            return InstallResult.Ok(result.ExitCode, result.Output + "\n(not installed — nothing to remove, treated as success)");
        }

        return InstallResult.Fail($"choco exited with {result.ExitCode}.", result.ExitCode, result.Output);
    }

    /// <summary>Chocolatey's way of saying the package was not there.</summary>
    public static bool NotInstalled(string? output)
    {
        if (string.IsNullOrEmpty(output))
        {
            return false;
        }

        return output.Contains("is not installed", StringComparison.OrdinalIgnoreCase)
            || output.Contains("Cannot uninstall a non-existent package", StringComparison.OrdinalIgnoreCase)
            || output.Contains("not installed. Cannot uninstall", StringComparison.OrdinalIgnoreCase);
    }

    public static IReadOnlyList<string>? BuildArguments(string action, string chocoId, string? version)
    {
        List<string>? arguments = action switch
        {
            "install" or "repair" => ["install", chocoId, "-y", "--no-progress"],
            "update" => ["upgrade", chocoId, "-y", "--no-progress"],
            "rollback" => version is null ? null : ["install", chocoId, "-y", "--no-progress", "--version", version, "--force"],
            // --all-versions for the same reason winget needs it: a machine
            // carrying two copies must end up with none, not with an error.
            "uninstall" => ["uninstall", chocoId, "-y", "--all-versions"],
            _ => null,
        };

        if (arguments is not null && action is "install" && version is not null)
        {
            arguments.AddRange(["--version", version]);
        }

        return arguments;
    }
}
