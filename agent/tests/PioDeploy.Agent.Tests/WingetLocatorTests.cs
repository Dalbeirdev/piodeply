using PioDeploy.Agent.Services;
using Xunit;

namespace PioDeploy.Agent.Tests;

public class WingetLocatorTests
{
    [Fact]
    public void PicksTheHighestVersionFolder()
    {
        var paths = new[]
        {
            @"C:\Program Files\WindowsApps\Microsoft.DesktopAppInstaller_1.22.10661.0_x64__8wekyb3d8bbwe\winget.exe",
            @"C:\Program Files\WindowsApps\Microsoft.DesktopAppInstaller_1.24.25200.0_x64__8wekyb3d8bbwe\winget.exe",
            @"C:\Program Files\WindowsApps\Microsoft.DesktopAppInstaller_1.9.25200.0_x64__8wekyb3d8bbwe\winget.exe",
        };

        var chosen = WingetLocator.PickNewest(paths);

        Assert.Equal(paths[1], chosen); // 1.24 > 1.22 > 1.9
    }

    [Fact]
    public void ReturnsNullWhenNoCandidates()
    {
        Assert.Null(WingetLocator.PickNewest([]));
    }

    [Fact]
    public void HandlesUnparseableFolderNames()
    {
        var only = new[] { @"C:\weird\path\winget.exe" };

        Assert.Equal(only[0], WingetLocator.PickNewest(only));
    }
}
