using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Installers;

/// <summary>Installs via the Windows Package Manager. winget resolves the
/// latest version from its own repository, verifies publisher hashes
/// itself, and installs silently.</summary>
public sealed class WingetInstaller : IInstaller
{
    /// <summary>winget: a newer/equal version is already installed.</summary>
    public const int AlreadyInstalled = unchecked((int) 0x8A15002B); // -1978335189

    /// <summary>winget: no applicable upgrade found.</summary>
    public const int NoApplicableUpgrade = unchecked((int) 0x8A15002C);

    /// <summary>winget with --no-upgrade: package already present, install
    /// cancelled ("A package version is already installed").</summary>
    public const int AlreadyInstalledNoUpgrade = unchecked((int) 0x8A150061);

    /// <summary>winget: nothing installed matches the id. For an uninstall
    /// that is the goal already reached, not a failure.</summary>
    public const int NoInstalledPackageFound = unchecked((int) 0x8A150014); // -1978335212

    /// <summary>winget: several copies of the package are installed (the
    /// classic per-user + machine-wide pair). Uninstall now passes
    /// --all-versions so this can no longer stop a removal.</summary>
    public const int MultiplePackagesFound = unchecked((int) 0x8A150016); // -1978335210

    private readonly IProcessRunner _processRunner;
    private readonly TimeSpan _timeout;

    public WingetInstaller(IProcessRunner processRunner, TimeSpan? timeout = null)
    {
        _processRunner = processRunner;
        _timeout = timeout ?? TimeSpan.FromMinutes(30);
    }

    public bool Supports(string installerType) => installerType == "winget";

    public async Task<InstallResult> ExecuteAsync(JobPayload job, CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(job.WingetId))
        {
            return InstallResult.Fail("Job has no winget id.");
        }

        var arguments = BuildArguments(job.Action, job.WingetId, job.Version);
        if (arguments is null)
        {
            // Rollback is supported; it just cannot be done without a target.
            // Saying "not supported" sent an operator looking at the wrong
            // thing entirely.
            return InstallResult.Fail(job.Action == "rollback"
                ? "Rollback needs a target version, and none was given."
                : $"Action '{job.Action}' is not supported for winget packages.");
        }

        // Resolve the real winget.exe — the bare "winget" alias isn't on the
        // PATH when the agent runs as the LocalSystem service.
        var result = await _processRunner.RunAsync(WingetLocator.Resolve(), arguments, _timeout, ct);

        if (result.TimedOut)
        {
            return InstallResult.Fail("winget timed out.", result.ExitCode, result.Output);
        }

        return result.ExitCode switch
        {
            0 => InstallResult.Ok(result.ExitCode, result.Output),
            AlreadyInstalled => InstallResult.Ok(result.ExitCode, result.Output + "\n(already installed — treated as success)"),
            AlreadyInstalledNoUpgrade => InstallResult.Ok(result.ExitCode, result.Output + "\n(already installed — treated as success)"),
            NoApplicableUpgrade => InstallResult.Ok(result.ExitCode, result.Output + "\n(no applicable upgrade — treated as success)"),

            // Removal is about the END STATE, not the act: a package that is
            // not there satisfies "uninstall" completely. Reporting failure
            // for it turned every already-clean machine into a red row and a
            // retry that could never go green.
            NoInstalledPackageFound when job.Action == "uninstall"
                => InstallResult.Ok(result.ExitCode, result.Output + "\n(not installed — nothing to remove, treated as success)"),

            _ => InstallResult.Fail($"winget exited with {result.ExitCode}.", result.ExitCode, result.Output),
        };
    }

    /// <summary>Argument construction is pure so it can be unit tested.</summary>
    public static IReadOnlyList<string>? BuildArguments(string action, string wingetId, string? version)
    {
        var common = new[]
        {
            "--id", wingetId, "--exact", "--silent",
            "--accept-package-agreements", "--accept-source-agreements",
            "--disable-interactivity",
        };

        // The agent runs as SYSTEM. A package whose default installer scope
        // is per-user (Brave is the classic case) would "succeed" into the
        // SYSTEM account's profile — exit 0, job green, and no browser for
        // any real user. --scope machine forces a machine-wide install; a
        // package that publishes no machine installer now fails loudly
        // instead of pretending, which is what an RMM must do.
        List<string>? arguments = action switch
        {
            // install means "ensure present": --no-upgrade stops winget from
            // attempting an in-place upgrade of an existing install (that is
            // what the separate 'update' action is for).
            "install" or "repair" => ["install", .. common, "--scope", "machine", "--no-upgrade"],
            "update" => ["upgrade", .. common],
            "rollback" => version is null ? null : ["install", .. common, "--scope", "machine", "--version", version, "--force"],
            // --all-versions: a machine often carries the SAME app twice —
            // a per-user copy (pre-1.4.1 agents installed those invisibly)
            // plus the machine-wide one. Without this winget refuses the
            // ambiguous removal (0x8A150016) and the job fails forever, so
            // "uninstall" would mean "uninstall if there happens to be
            // exactly one". Removal means gone: every copy, one click.
            "uninstall" => [
                "uninstall", "--id", wingetId, "--exact", "--silent",
                "--disable-interactivity", "--all-versions",
            ],
            _ => null,
        };

        if (arguments is not null && action is "install" && version is not null)
        {
            arguments.AddRange(["--version", version]);
        }

        return arguments;
    }
}
