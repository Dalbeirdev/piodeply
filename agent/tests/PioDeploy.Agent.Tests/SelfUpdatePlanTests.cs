using PioDeploy.Agent.Services;
using Xunit;

namespace PioDeploy.Agent.Tests;

public class SelfUpdatePlanTests
{
    private const string Bundle = "https://piodeploy.com/api/v1/agent/bundle";

    [Fact]
    public void UpdatesWhenTheServerOffersANewerVersion()
    {
        Assert.True(SelfUpdatePlan.ShouldUpdate("1.2.0", "1.3.4", Bundle));
        Assert.True(SelfUpdatePlan.ShouldUpdate("1.3.3", "1.3.4", Bundle));
    }

    [Fact]
    public void DoesNotUpdateWhenAlreadyCurrent()
    {
        Assert.False(SelfUpdatePlan.ShouldUpdate("1.3.4", "1.3.4", Bundle));
    }

    [Fact]
    public void DoesNotDowngrade()
    {
        Assert.False(SelfUpdatePlan.ShouldUpdate("1.3.4", "1.3.0", Bundle));
    }

    [Fact]
    public void DoesNothingWithoutABundleToFetch()
    {
        Assert.False(SelfUpdatePlan.ShouldUpdate("1.2.0", "1.3.4", null));
        Assert.False(SelfUpdatePlan.ShouldUpdate("1.2.0", "1.3.4", ""));
    }

    [Fact]
    public void DoesNothingWithoutAServerVersion()
    {
        Assert.False(SelfUpdatePlan.ShouldUpdate("1.2.0", null, Bundle));
        Assert.False(SelfUpdatePlan.ShouldUpdate("1.2.0", "", Bundle));
    }

    [Fact]
    public void AMalformedVersionNeverTriggersAnUpdate()
    {
        Assert.False(SelfUpdatePlan.ShouldUpdate("1.2.0", "latest", Bundle));
        Assert.False(SelfUpdatePlan.ShouldUpdate("dev", "1.3.4", Bundle));
    }

    [Fact]
    public void ReinstallIgnoresVersionsButNeedsABundle()
    {
        // A reinstall is an operator's "replace it regardless" — same-version
        // is the normal case, so no comparison. But with nothing to download
        // it must not stage a swap that would install nothing.
        Assert.True(SelfUpdatePlan.ShouldReinstall(true, Bundle));
        Assert.False(SelfUpdatePlan.ShouldReinstall(true, null));
        Assert.False(SelfUpdatePlan.ShouldReinstall(true, ""));
        Assert.False(SelfUpdatePlan.ShouldReinstall(false, Bundle));
    }

    [Fact]
    public void UninstallScriptRemovesServiceFilesAndState()
    {
        var script = SelfUpdater.BuildUninstallScript(@"C:\Program Files\PioDeploy\Agent");

        Assert.Contains("sc.exe delete", script);
        Assert.Contains(@"C:\Program Files\PioDeploy\Agent", script);
        Assert.Contains("Join-Path $env:ProgramData 'PioDeploy'", script);
        // Both one-shot tasks must be cleaned up, its own last.
        Assert.Contains("PioDeployAgentSelfUpdate", script);
        Assert.Contains("PioDeployAgentUninstall", script);
    }

    [Fact]
    public void UninstallScriptLogsOutsideTheDirectoriesItDeletes()
    {
        // The uninstall log is the only trace left when something goes wrong;
        // writing it under ProgramData\PioDeploy would delete the evidence.
        var script = SelfUpdater.BuildUninstallScript(@"C:\i");

        Assert.Contains(@"Join-Path $env:windir 'Temp\piodeploy-uninstall.log'", script);
        Assert.DoesNotContain(@"$state\", script.Split('\n').First(l => l.Contains("$log")));
    }

    // The swap script runs unattended as SYSTEM on every fleet machine; these
    // pin the behaviours a bad script broke in the field.

    [Fact]
    public void SwapScriptNeverOverwritesTheMachinesConfig()
    {
        // The bundle ships a placeholder appsettings.json (a template for
        // fresh enrollments). Copying it over the live one disconnects the
        // agent from the server — the update "succeeds" and the machine
        // silently goes dark.
        var script = SelfUpdater.BuildSwapScript(
            @"C:\ProgramData\PioDeploy\update\staging",
            @"C:\Program Files\PioDeploy\Agent",
            @"C:\ProgramData\PioDeploy\update");

        Assert.Contains("-Exclude 'appsettings*.json'", script);
    }

    [Fact]
    public void SwapScriptLogsBeforeTouchingAnything()
    {
        // The first observable act must be a log line: a helper that dies
        // early otherwise leaves no trace, which is exactly what made the
        // session-0 launch failure invisible.
        var script = SelfUpdater.BuildSwapScript(@"C:\s", @"C:\i", @"C:\r");

        var logsFirst = script.IndexOf("Log \"Helper started", StringComparison.Ordinal);
        var firstAction = script.IndexOf("Stop-Service", StringComparison.Ordinal);
        Assert.True(logsFirst >= 0 && logsFirst < firstAction);
    }

    [Fact]
    public void SwapScriptRollsBackWhenTheServiceDoesNotComeUp()
    {
        var script = SelfUpdater.BuildSwapScript(@"C:\s", @"C:\i", @"C:\r");

        Assert.Contains("service did not start after update", script);
        Assert.Contains("Rolled back to previous version", script);
    }
}
