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
