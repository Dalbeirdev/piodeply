using Microsoft.Extensions.Logging.Abstractions;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Tests;

file sealed class NoopProcessRunner : IProcessRunner
{
    public Task<ProcessResult> RunAsync(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken ct)
        => Task.FromResult(new ProcessResult(1, string.Empty, false)); // choco/winget "not available"
}

public class SoftwareCollectorTests
{
    [Fact]
    public void Parses_Choco_Limit_Output()
    {
        var output = "git|2.46.0\r\n7zip|24.08\r\nchocolatey|2.3.0\r\n";

        var entries = WindowsSoftwareCollector.ParseChocoOutput(output);

        Assert.Equal(3, entries.Count);
        Assert.Equal("git", entries[0].Name);
        Assert.Equal("2.46.0", entries[0].Version);
        Assert.All(entries, e => Assert.Equal("choco", e.Source));
    }

    [Fact]
    public void Choco_Parser_Ignores_Malformed_Lines()
    {
        var entries = WindowsSoftwareCollector.ParseChocoOutput("garbage line\nname|1.0\n|1.0\n");

        Assert.Single(entries);
        Assert.Equal("name", entries[0].Name);
    }

    [Fact]
    public void Parses_Winget_Export_Json()
    {
        var json = """
        {
          "Sources": [
            {
              "Packages": [
                { "PackageIdentifier": "Git.Git", "Version": "2.46.0" },
                { "PackageIdentifier": "7zip.7zip" }
              ],
              "SourceDetails": { "Name": "winget" }
            }
          ]
        }
        """;

        var entries = WindowsSoftwareCollector.ParseWingetExport(json);

        Assert.Equal(2, entries.Count);
        Assert.Equal("Git.Git", entries[0].Name);
        Assert.Equal("2.46.0", entries[0].Version);
        Assert.Null(entries[1].Version);
        Assert.All(entries, e => Assert.Equal("winget", e.Source));
    }

    [Fact]
    public void Winget_Parser_Handles_Missing_Sources()
    {
        Assert.Empty(WindowsSoftwareCollector.ParseWingetExport("{}"));
    }

    [Fact]
    public async Task Registry_Scan_Finds_Installed_Software_On_Windows()
    {
        if (!OperatingSystem.IsWindows())
        {
            return;
        }

        var collector = new WindowsSoftwareCollector(new NoopProcessRunner(), NullLogger<WindowsSoftwareCollector>.Instance);

        var entries = await collector.CollectAsync(CancellationToken.None);

        Assert.NotEmpty(entries);                                    // every real Windows box has software
        Assert.All(entries, e => Assert.False(string.IsNullOrWhiteSpace(e.Name)));
        Assert.Contains(entries, e => e.Source is "registry" or "msi");
        Assert.Contains(entries, e => e.Version is not null);
    }
}
