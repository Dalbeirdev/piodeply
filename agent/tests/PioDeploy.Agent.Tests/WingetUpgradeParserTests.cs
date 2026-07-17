using PioDeploy.Agent.Services;
using Xunit;

namespace PioDeploy.Agent.Tests;

public class WingetUpgradeParserTests
{
    private const string RealOutput = """
Name                           Id                        Version        Available      Source
-----------------------------------------------------------------------------------------------
Google Chrome                  Google.Chrome             138.0.7615.129 141.0.7390.55  winget
Notepad++ (64-bit x64)         Notepad++.Notepad++       8.4.7          8.6.9          winget
2 upgrades available.
""";

    [Fact]
    public void ReadsIdsAndAvailableVersions()
    {
        var result = WingetUpgradeParser.Parse(RealOutput);

        Assert.Equal(2, result.Count);
        Assert.Equal("141.0.7390.55", result["Google.Chrome"]);
        Assert.Equal("8.6.9", result["Notepad++.Notepad++"]);
    }

    /// <summary>Names and versions contain spaces, so splitting on whitespace
    /// would mangle them — columns come from the header offsets.</summary>
    [Fact]
    public void ANameContainingSpacesDoesNotShiftTheColumns()
    {
        var result = WingetUpgradeParser.Parse(RealOutput);

        Assert.Equal("8.6.9", result["Notepad++.Notepad++"]);
    }

    [Fact]
    public void MatchingIsCaseInsensitiveLikeWingetIds()
    {
        var result = WingetUpgradeParser.Parse(RealOutput);

        Assert.Equal("141.0.7390.55", result["google.chrome"]);
    }

    /// <summary>winget prints "Unknown" when it cannot pin a version. Storing
    /// that would show "138.0 -> Unknown available".</summary>
    [Fact]
    public void UnknownIsNotTreatedAsAVersion()
    {
        var output = """
Name           Id                Version   Available   Source
-------------------------------------------------------------
Some App       Vendor.SomeApp    1.0       Unknown     winget
""";

        Assert.Empty(WingetUpgradeParser.Parse(output));
    }

    [Fact]
    public void NothingToUpgradeYieldsNothing()
    {
        Assert.Empty(WingetUpgradeParser.Parse("No installed package found matching input criteria."));
        Assert.Empty(WingetUpgradeParser.Parse(string.Empty));
        Assert.Empty(WingetUpgradeParser.Parse("   "));
    }

    /// <summary>A localised or changed table must produce nothing rather than
    /// garbage: a wrong "update available" sends someone chasing a version
    /// that does not exist.</summary>
    [Fact]
    public void AnUnrecognisableTableIsIgnoredRatherThanGuessedAt()
    {
        var german = """
Name           Kennung           Version   Verfügbar   Quelle
-------------------------------------------------------------
Google Chrome  Google.Chrome     138.0     141.0       winget
""";

        Assert.Empty(WingetUpgradeParser.Parse(german));
    }

    [Fact]
    public void TheDashedRuleIsNotReadAsAPackage()
    {
        var result = WingetUpgradeParser.Parse(RealOutput);

        Assert.DoesNotContain(result.Keys, k => k.StartsWith("---", StringComparison.Ordinal));
    }

    [Fact]
    public void TheTrailingSummaryLineIsNotReadAsAPackage()
    {
        var result = WingetUpgradeParser.Parse(RealOutput);

        Assert.DoesNotContain(result.Keys, k => k.Contains("upgrades available", StringComparison.OrdinalIgnoreCase));
    }

    [Fact]
    public void HandlesCrlfLineEndings()
    {
        var result = WingetUpgradeParser.Parse(RealOutput.Replace("\n", "\r\n"));

        Assert.Equal("141.0.7390.55", result["Google.Chrome"]);
    }

    [Fact]
    public void AcceptsPreReleaseStyleVersions()
    {
        var output = """
Name        Id             Version   Available     Source
---------------------------------------------------------
Thing       Vendor.Thing   1.0       2.0-beta.1    winget
""";

        Assert.Equal("2.0-beta.1", WingetUpgradeParser.Parse(output)["Vendor.Thing"]);
    }
}
