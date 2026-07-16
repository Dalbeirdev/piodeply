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

        var job = new JobPayload { InstallerType = "winget", WingetId = "Google.Chrome" };

        Assert.Equal("141.0", InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void FindsTheVersionOfAChocoPackage()
    {
        var software = new[] { Entry("googlechrome", "141.0", "choco") };
        var job = new JobPayload { InstallerType = "choco", ChocoId = "googlechrome" };

        Assert.Equal("141.0", InstalledVersionResolver.Resolve(software, job));
    }

    /// <summary>
    /// The source must come from the installer type, not from whichever id is
    /// populated. Chrome legitimately has both a winget id and a choco id; a
    /// choco package looked up as winget reads as absent forever.
    /// </summary>
    [Fact]
    public void AChocoPackageCarryingAWingetIdIsStillFoundByItsChocoId()
    {
        var software = new[] { Entry("googlechrome", "141.0", "choco") };

        var job = new JobPayload
        {
            InstallerType = "choco",
            WingetId = "Google.Chrome", // also set, and irrelevant here
            ChocoId = "googlechrome",
        };

        Assert.Equal("141.0", InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void AWingetPackageIsNotMatchedAgainstAChocoRow()
    {
        var software = new[] { Entry("googlechrome", "141.0", "choco") };

        var job = new JobPayload
        {
            InstallerType = "winget",
            WingetId = "Google.Chrome",
            ChocoId = "googlechrome",
        };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void ABinaryPackageHasNoManagerRowToMatch()
    {
        var software = new[] { Entry("Legacy Tool", "3.1", "registry") };
        var job = new JobPayload { InstallerType = "msi", WingetId = "Some.Id" };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void DoesNotMatchTheSameNameFromADifferentSource()
    {
        // A registry DisplayName is not a package id — matching it would
        // report a version for the wrong thing.
        var software = new[] { Entry("Google.Chrome", "141.0", "registry") };
        var job = new JobPayload { InstallerType = "winget", WingetId ="Google.Chrome" };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void ReturnsNullWhenThePackageIsGone()
    {
        // The uninstall case: absent is a real answer.
        var software = new[] { Entry("Mozilla.Firefox", "130.0", "winget") };
        var job = new JobPayload { InstallerType = "winget", WingetId ="Google.Chrome" };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void ReturnsNullForAPackageWithNoManagerId()
    {
        var software = new[] { Entry("Legacy Tool", "3.1", "registry") };
        var job = new JobPayload { InstallerType = "winget", WingetId =null, ChocoId = null };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void MatchingIsCaseInsensitive()
    {
        var software = new[] { Entry("google.chrome", "141.0", "WinGet") };
        var job = new JobPayload { InstallerType = "winget", WingetId ="Google.Chrome" };

        Assert.Equal("141.0", InstalledVersionResolver.Resolve(software, job));
    }

    [Fact]
    public void HandlesAnEmptyInventory()
    {
        Assert.Null(InstalledVersionResolver.Resolve([], new JobPayload { InstallerType = "winget", WingetId ="Google.Chrome" }));
    }

    [Fact]
    public void APresentPackageWithNoReportedVersionStaysNull()
    {
        var software = new[] { Entry("Google.Chrome", null, "winget") };
        var job = new JobPayload { InstallerType = "winget", WingetId ="Google.Chrome" };

        Assert.Null(InstalledVersionResolver.Resolve(software, job));
    }
}
