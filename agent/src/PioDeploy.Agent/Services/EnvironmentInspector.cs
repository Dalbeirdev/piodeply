using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Services;

public interface IEnvironmentInspector
{
    Task<IReadOnlyList<EnvironmentCheck>> InspectAsync(CancellationToken ct);
}

/// <summary>Readiness self-checks: can this machine actually sync and deploy
/// software from where the agent sits (the SYSTEM account)? Each failure here
/// is one this fleet hit in the field, so the server can flag the machine and
/// name the fix rather than let it surface one failed job at a time.</summary>
public sealed class EnvironmentInspector : IEnvironmentInspector
{
    private readonly IProcessRunner _processRunner;
    private readonly Func<string> _wingetPath;

    public EnvironmentInspector(IProcessRunner processRunner, Func<string>? wingetPath = null)
    {
        _processRunner = processRunner;
        _wingetPath = wingetPath ?? WingetLocator.Resolve;
    }

    public async Task<IReadOnlyList<EnvironmentCheck>> InspectAsync(CancellationToken ct)
    {
        return new[]
        {
            await CheckWingetAsync(ct),
        };
    }

    /// <summary>The one that mattered most: winget must both resolve to a real
    /// exe and actually run under this account, or nothing syncs.</summary>
    private async Task<EnvironmentCheck> CheckWingetAsync(CancellationToken ct)
    {
        var path = _wingetPath();

        if (path == WingetLocator.PathFallback)
        {
            return new EnvironmentCheck
            {
                Key = "winget",
                Ok = false,
                Detail = "winget.exe was not found under WindowsApps for the SYSTEM account.",
            };
        }

        try
        {
            var result = await _processRunner.RunAsync(path, ["--version"], TimeSpan.FromSeconds(30), ct);

            return result.ExitCode == 0
                ? new EnvironmentCheck { Key = "winget", Ok = true, Detail = result.Output.Trim() }
                : new EnvironmentCheck
                {
                    Key = "winget",
                    Ok = false,
                    Detail = $"winget --version exited {result.ExitCode}: {Truncate(result.Output)}",
                };
        }
        catch (Exception ex)
        {
            return new EnvironmentCheck { Key = "winget", Ok = false, Detail = Truncate(ex.Message) };
        }
    }

    private static string Truncate(string value)
        => value.Length <= 200 ? value : value[..200];
}
