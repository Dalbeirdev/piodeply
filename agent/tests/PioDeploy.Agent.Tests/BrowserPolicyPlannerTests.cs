using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;
using Xunit;

namespace PioDeploy.Agent.Tests;

public class BrowserPolicyPlannerTests
{
    /* ── Manifest diff / rollback ─────────────────────────────────────── */

    [Fact]
    public void RemovesRegistryValuesNoLongerManaged()
    {
        var previous = new BrowserPolicyManifest
        {
            RegistryValues =
            [
                new ManagedRegistryValue { Path = @"SOFTWARE\Policies\Google\Chrome", Name = "IncognitoModeAvailability" },
                new ManagedRegistryValue { Path = @"SOFTWARE\Policies\Microsoft\Edge", Name = "InPrivateModeAvailability" },
            ],
        };

        var desired = new[]
        {
            new ManagedRegistryValue { Path = @"SOFTWARE\Policies\Google\Chrome", Name = "IncognitoModeAvailability" },
        };

        var stale = BrowserPolicyPlanner.RegistryValuesToRemove(previous, desired);

        Assert.Single(stale);
        Assert.Equal("InPrivateModeAvailability", stale[0].Name);
    }

    [Fact]
    public void RegistryComparisonIsCaseInsensitive()
    {
        var previous = new BrowserPolicyManifest
        {
            RegistryValues = [new ManagedRegistryValue { Path = @"software\policies\google\chrome", Name = "incognitomodeavailability" }],
        };

        var desired = new[]
        {
            new ManagedRegistryValue { Path = @"SOFTWARE\Policies\Google\Chrome", Name = "IncognitoModeAvailability" },
        };

        Assert.Empty(BrowserPolicyPlanner.RegistryValuesToRemove(previous, desired));
    }

    [Fact]
    public void RemovesFirefoxKeysNoLongerManaged()
    {
        var previous = new BrowserPolicyManifest { FirefoxKeys = ["DisablePrivateBrowsing", "PasswordManagerEnabled"] };

        var stale = BrowserPolicyPlanner.FirefoxKeysToRemove(previous, ["DisablePrivateBrowsing"]);

        Assert.Equal(["PasswordManagerEnabled"], stale);
    }

    /* ── Firefox policies.json merge ──────────────────────────────────── */

    [Fact]
    public void CreatesPoliciesJsonFromScratch()
    {
        var json = BrowserPolicyPlanner.MergeFirefoxPolicies(
            existingJson: null,
            setKeys: new Dictionary<string, bool> { ["DisablePrivateBrowsing"] = true },
            removeKeys: []);

        Assert.NotNull(json);
        Assert.Contains("\"DisablePrivateBrowsing\": true", json);
        Assert.Contains("\"policies\"", json);
    }

    [Fact]
    public void MergePreservesUnmanagedKeys()
    {
        const string existing = """{"policies":{"DisableTelemetry":true,"Homepage":{"URL":"https://intranet"}}}""";

        var json = BrowserPolicyPlanner.MergeFirefoxPolicies(
            existing,
            new Dictionary<string, bool> { ["DisablePrivateBrowsing"] = true },
            removeKeys: []);

        Assert.NotNull(json);
        Assert.Contains("DisableTelemetry", json);
        Assert.Contains("intranet", json);
        Assert.Contains("DisablePrivateBrowsing", json);
    }

    [Fact]
    public void MergeIsIdempotent()
    {
        var first = BrowserPolicyPlanner.MergeFirefoxPolicies(
            null, new Dictionary<string, bool> { ["DisablePrivateBrowsing"] = true }, [])!;

        var second = BrowserPolicyPlanner.MergeFirefoxPolicies(
            first, new Dictionary<string, bool> { ["DisablePrivateBrowsing"] = true }, []);

        Assert.Null(second); // nothing changed → no write
    }

    [Fact]
    public void MergeRemovesRolledBackKeys()
    {
        const string existing = """{"policies":{"DisablePrivateBrowsing":true,"DisableTelemetry":true}}""";

        var json = BrowserPolicyPlanner.MergeFirefoxPolicies(
            existing, new Dictionary<string, bool>(), ["DisablePrivateBrowsing"]);

        Assert.NotNull(json);
        Assert.DoesNotContain("DisablePrivateBrowsing", json);
        Assert.Contains("DisableTelemetry", json);
    }

    [Fact]
    public void MergeSurvivesCorruptJson()
    {
        var json = BrowserPolicyPlanner.MergeFirefoxPolicies(
            "{{not json", new Dictionary<string, bool> { ["DisablePrivateBrowsing"] = true }, []);

        Assert.NotNull(json);
        Assert.Contains("DisablePrivateBrowsing", json);
    }

    /* ── Status mapping ───────────────────────────────────────────────── */

    [Theory]
    [InlineData(false, true, true, false, false, "not_installed")]
    [InlineData(true, false, true, false, false, "unsupported")]
    [InlineData(true, true, false, true, false, "non_compliant")]
    [InlineData(true, true, true, true, true, "pending_restart")]
    [InlineData(true, true, true, true, false, "compliant")]  // changed, browser closed
    [InlineData(true, true, true, false, true, "compliant")]  // already correct, browser open
    public void StatusMappingCoversTheMatrix(bool installed, bool supported, bool correct, bool changed, bool running, string expected)
    {
        Assert.Equal(expected, BrowserPolicyPlanner.StatusFor(installed, supported, correct, changed, running));
    }
}
