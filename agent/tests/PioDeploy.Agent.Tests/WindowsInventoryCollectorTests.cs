using Microsoft.Extensions.Logging.Abstractions;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Tests;

public class WindowsInventoryCollectorTests
{
    [Fact]
    public void Collect_Returns_Core_System_Facts()
    {
        if (!OperatingSystem.IsWindows())
        {
            return; // WMI collector is Windows-only by design
        }

        var collector = new WindowsInventoryCollector(NullLogger<WindowsInventoryCollector>.Instance);

        var payload = collector.Collect();

        Assert.Equal(Environment.MachineName, payload.Hostname);
        Assert.NotNull(payload.OsName);
        Assert.Contains("Windows", payload.OsName);
        Assert.True(payload.RamBytes > 0);
        Assert.True(payload.DiskTotalBytes > 0);
        Assert.True(payload.DiskFreeBytes > 0 && payload.DiskFreeBytes <= payload.DiskTotalBytes);
        Assert.NotNull(payload.Cpu);
        Assert.NotNull(payload.PrivateIp);
        Assert.NotNull(payload.MacAddress);
        Assert.Matches("^([0-9A-F]{2}:){5}[0-9A-F]{2}$", payload.MacAddress!);
    }
}
