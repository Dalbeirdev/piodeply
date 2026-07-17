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
}
