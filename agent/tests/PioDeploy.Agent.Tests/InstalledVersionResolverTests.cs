using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;
using Xunit;

namespace PioDeploy.Agent.Tests;

public class InstalledVersionResolverTests
{
    private static SoftwareEntry Entry(string name, string? version, string source)
        => new() { Name = name, Version = version, Source = source };

    [Fact]
    public void FindsTheVersionOfAWingetPackage()
    {
        var software = new[]
        {
            Entry("Mozilla.Firefox", "130.0", "winget"),
            Entry("Google.Chrome", "141.0", "winget"),
        };

        var job = new JobPayload { WingetId = "Google.Chrome" };

        Assert.Equal("141.0", InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void FallsBackToChocoWhenThereIsNoWingetId()
    {
        var software = new[] { Entry("googlechrome", "141.0", "choco") };
        var job = new JobPayload { ChocoId = "googlechrome" };

        Assert.Equal("141.0", InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void DoesNotMatchTheSameNameFromADifferentSource()
    {
        // A registry DisplayName is not a package id — matching it would
        // report a version for the wrong thing.
        var software = new[] { Entry("Google.Chrome", "141.0", "registry") };
        var job = new JobPayload { WingetId = "Google.Chrome" };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void ReturnsNullWhenThePackageIsGone()
    {
        // The uninstall case: absent is a real answer.
        var software = new[] { Entry("Mozilla.Firefox", "130.0", "winget") };
        var job = new JobPayload { WingetId = "Google.Chrome" };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void ReturnsNullForAPackageWithNoManagerId()
    {
        var software = new[] { Entry("Legacy Tool", "3.1", "registry") };
        var job = new JobPayload { WingetId = null, ChocoId = null };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void MatchingIsCaseInsensitive()
    {
        var software = new[] { Entry("google.chrome", "141.0", "WinGet") };
        var job = new JobPayload { WingetId = "Google.Chrome" };

        Assert.Equal("141.0", InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void HandlesAnEmptyInventory()
    {
        Assert.Null(InstalledVersionResolver.Resolve([], new JobPayload { WingetId = "Google.Chrome" }));
    }

    [Fact]
    public void APresentPackageWithNoReportedVersionStaysNull()
    {
        var software = new[] { Entry("Google.Chrome", null, "winget") };
        var job = new JobPayload { WingetId = "Google.Chrome" };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }
}
