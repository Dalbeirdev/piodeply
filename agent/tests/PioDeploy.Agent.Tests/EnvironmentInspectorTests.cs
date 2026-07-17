using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Tests;

/// <summary>A process runner with a canned result, so we can drive the winget
/// version probe down each branch without touching the machine.</summary>
file sealed class StubProcessRunner : IProcessRunner
{
    private readonly ProcessResult _result;
    public List<string> Commands { get; } = [];

    public StubProcessRunner(int exitCode, string output)
        => _result = new ProcessResult(exitCode, output, false);

    public Task<ProcessResult> RunAsync(string fileName, IReadOnlyList<string> arguments, TimeSpan timeout, CancellationToken ct)
    {
        Commands.Add(fileName);
        return Task.FromResult(_result);
    }
}

public class EnvironmentInspectorTests
{
    [Fact]
    public async Task Winget_Unresolvable_Is_Reported_Not_Ready_Without_Running_Anything()
    {
        var runner = new StubProcessRunner(0, "irrelevant");
        var inspector = new EnvironmentInspector(runner, wingetPath: () => WingetLocator.PathFallback);

        var checks = await inspector.InspectAsync(CancellationToken.None);

        var winget = Assert.Single(checks, c => c.Key == "winget");
        Assert.False(winget.Ok);
        // Never shell out to the bare alias — that is the failure we are detecting.
        Assert.Empty(runner.Commands);
    }

    [Fact]
    public async Task Winget_That_Runs_Cleanly_Is_Ready()
    {
        const string resolved = @"C:\Program Files\WindowsApps\winget.exe";
        var runner = new StubProcessRunner(0, "v1.9.25200");
        var inspector = new EnvironmentInspector(runner, wingetPath: () => resolved);

        var checks = await inspector.InspectAsync(CancellationToken.None);

        var winget = Assert.Single(checks, c => c.Key == "winget");
        Assert.True(winget.Ok);
        Assert.Equal("v1.9.25200", winget.Detail);
        Assert.Contains(resolved, runner.Commands);
    }

    [Fact]
    public async Task Winget_That_Errors_Is_Reported_Not_Ready()
    {
        var runner = new StubProcessRunner(-1073741515, "The application failed to start.");
        var inspector = new EnvironmentInspector(runner, wingetPath: () => @"C:\winget.exe");

        var checks = await inspector.InspectAsync(CancellationToken.None);

        var winget = Assert.Single(checks, c => c.Key == "winget");
        Assert.False(winget.Ok);
        Assert.Contains("-1073741515", winget.Detail);
    }
}
