using System.Management;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.Versioning;
using Microsoft.Extensions.Logging;
using Microsoft.Win32;
using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Services;

public interface IInventoryCollector
{
    InventoryPayload Collect();
}

/// <summary>Collects system inventory from WMI, the registry, and .NET
/// runtime APIs. Every probe is individually fault-tolerant: a machine that
/// refuses one query still reports the rest.</summary>
[SupportedOSPlatform("windows")]
public sealed class WindowsInventoryCollector : IInventoryCollector
{
    private readonly ILogger<WindowsInventoryCollector> _logger;

    public WindowsInventoryCollector(ILogger<WindowsInventoryCollector> logger)
    {
        _logger = logger;
    }

    public InventoryPayload Collect()
    {
        var payload = new InventoryPayload
        {
            Hostname = Environment.MachineName,
        };

        Probe("computer system", () =>
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT Manufacturer, Model, TotalPhysicalMemory FROM Win32_ComputerSystem");
            foreach (var obj in searcher.Get())
            {
                payload.Manufacturer = obj["Manufacturer"]?.ToString()?.Trim();
                payload.Model = obj["Model"]?.ToString()?.Trim();
                payload.RamBytes = Convert.ToInt64(obj["TotalPhysicalMemory"] ?? 0);
            }
        });

        Probe("bios", () =>
        {
            using var searcher = new ManagementObjectSearcher("SELECT SerialNumber FROM Win32_BIOS");
            foreach (var obj in searcher.Get())
            {
                payload.SerialNumber = obj["SerialNumber"]?.ToString()?.Trim();
            }
        });

        Probe("operating system", () =>
        {
            using var searcher = new ManagementObjectSearcher(
                "SELECT Caption, Version, BuildNumber FROM Win32_OperatingSystem");
            foreach (var obj in searcher.Get())
            {
                payload.OsName = obj["Caption"]?.ToString()?.Trim();
                payload.OsVersion = obj["Version"]?.ToString()?.Trim();
                payload.WindowsBuild = obj["BuildNumber"]?.ToString()?.Trim();
            }
        });

        Probe("cpu", () =>
        {
            using var searcher = new ManagementObjectSearcher("SELECT Name FROM Win32_Processor");
            foreach (var obj in searcher.Get())
            {
                payload.Cpu = obj["Name"]?.ToString()?.Trim();
                break;
            }
        });

        Probe("system disk", () =>
        {
            var systemDrive = Path.GetPathRoot(Environment.SystemDirectory)!;
            var drive = new DriveInfo(systemDrive);
            payload.DiskTotalBytes = drive.TotalSize;
            payload.DiskFreeBytes = drive.AvailableFreeSpace;
        });

        Probe("network", () =>
        {
            var nic = NetworkInterface.GetAllNetworkInterfaces()
                .Where(n => n.OperationalStatus == OperationalStatus.Up
                            && n.NetworkInterfaceType != NetworkInterfaceType.Loopback
                            && n.NetworkInterfaceType != NetworkInterfaceType.Tunnel)
                .OrderByDescending(n => n.Speed)
                .FirstOrDefault();

            if (nic is null)
            {
                return;
            }

            payload.MacAddress = string.Join(":", nic.GetPhysicalAddress()
                .GetAddressBytes().Select(b => b.ToString("X2")));

            payload.PrivateIp = nic.GetIPProperties().UnicastAddresses
                .FirstOrDefault(a => a.Address.AddressFamily == AddressFamily.InterNetwork)?
                .Address.ToString();
        });

        Probe("secure boot", () =>
        {
            using var key = Registry.LocalMachine.OpenSubKey(
                @"SYSTEM\CurrentControlSet\Control\SecureBoot\State");
            var value = key?.GetValue("UEFISecureBootEnabled");
            if (value is not null)
            {
                payload.SecureBoot = Convert.ToInt32(value) == 1;
            }
        });

        Probe("tpm", () =>
        {
            using var searcher = new ManagementObjectSearcher(
                @"root\cimv2\Security\MicrosoftTpm",
                "SELECT IsEnabled_InitialValue, SpecVersion FROM Win32_Tpm");
            foreach (var obj in searcher.Get())
            {
                payload.TpmEnabled = obj["IsEnabled_InitialValue"] as bool?;
                payload.TpmVersion = obj["SpecVersion"]?.ToString()?.Split(',')[0].Trim();
            }
        });

        return payload;
    }

    private void Probe(string what, Action probe)
    {
        try
        {
            probe();
        }
        catch (Exception ex)
        {
            // Elevation-dependent probes (e.g. TPM) fail on locked-down
            // machines — inventory continues without them.
            _logger.LogDebug(ex, "Inventory probe '{Probe}' failed", what);
        }
    }
}
