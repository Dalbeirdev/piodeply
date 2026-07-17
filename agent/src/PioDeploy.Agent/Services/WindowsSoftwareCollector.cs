using System.Runtime.Versioning;
using System.Text.Json;
using Microsoft.Extensions.Logging;
using Microsoft.Win32;
using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Services;

public interface ISoftwareCollector
{
    Task<IReadOnlyList<SoftwareEntry>> CollectAsync(CancellationToken ct);
}

/// <summary>Detects installed software from the sources that are safe and
/// authoritative on Windows:
///  - Registry Uninstall keys (HKLM 64-bit, HKLM WOW6432Node, HKCU) —
///    deliberately NOT Win32_Product, which is slow and triggers MSI repairs.
///    Entries flagged WindowsInstaller=1 are tagged source=msi.
///  - Chocolatey local list (machine-readable `--limit-output`).
///  - winget export (JSON) — yields exact package identifiers that match the
///    catalogue's winget IDs.</summary>
[SupportedOSPlatform("windows")]
public sealed class WindowsSoftwareCollector : ISoftwareCollector
{
    private const int MaxEntries = 2500;

    private static readonly string[] UninstallKeyPaths =
    [
        @"SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall",
        @"SOFTWARE\WOW6432Node\Microsoft\Windows\CurrentVersion\Uninstall",
    ];

    private readonly IProcessRunner _processRunner;
    private readonly ILogger<WindowsSoftwareCollector> _logger;
    private readonly Func<string> _wingetPath;

    /// <param name="wingetPath">Overridable so tests can assert what is run;
    /// production resolves the real winget.exe.</param>
    public WindowsSoftwareCollector(
        IProcessRunner processRunner,
        ILogger<WindowsSoftwareCollector> logger,
        Func<string>? wingetPath = null)
    {
        _processRunner = processRunner;
        _logger = logger;
        _wingetPath = wingetPath ?? WingetLocator.Resolve;
    }

    public async Task<IReadOnlyList<SoftwareEntry>> CollectAsync(CancellationToken ct)
    {
        var entries = new List<SoftwareEntry>();

        Probe("registry (HKLM)", () => entries.AddRange(ScanHive(Registry.LocalMachine)));
        Probe("registry (HKCU)", () => entries.AddRange(ScanHive(Registry.CurrentUser)));

        await ProbeAsync("chocolatey", async () =>
        {
            var result = await _processRunner.RunAsync("choco",
                ["list", "--limit-output"], TimeSpan.FromMinutes(2), ct);
            if (result.ExitCode == 0)
            {
                entries.AddRange(ParseChocoOutput(result.Output));
            }
        });

        var wingetEntries = 0;

        await ProbeAsync("winget export", async () =>
        {
            var exportPath = Path.Combine(Path.GetTempPath(), $"piodeploy-winget-{Guid.NewGuid():N}.json");
            try
            {
                // Resolve the real winget.exe: the bare alias is not on the
                // PATH of the LocalSystem account this service runs under, so
                // "winget" fails, the probe swallows it, and the machine looks
                // like it has no package-managed software at all.
                var winget = _wingetPath();

                // The fallback is correct for an interactive session but wrong
                // for this service, and it is silent — which is how the bug it
                // replaced went unnoticed. WindowsApps grants SYSTEM full
                // control by default, so reaching here usually means its ACLs
                // were changed on this machine.
                if (winget == WingetLocator.PathFallback)
                {
                    _logger.LogWarning(
                        "Could not resolve winget.exe under WindowsApps; falling back to the PATH alias, " +
                        "which does not exist for the LocalSystem service. Any winget packages on this " +
                        "machine will go undetected. Check the permissions on {Root}.",
                        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "WindowsApps"));
                }

                var result = await _processRunner.RunAsync(winget,
                    ["export", "-o", exportPath, "--include-versions", "--accept-source-agreements", "--disable-interactivity"],
                    TimeSpan.FromMinutes(3), ct);

                // winget export returns non-zero when some packages are
                // unavailable in sources — the file is still written.
                if (File.Exists(exportPath))
                {
                    var parsed = ParseWingetExport(await File.ReadAllTextAsync(exportPath, ct));
                    wingetEntries = parsed.Count;
                    entries.AddRange(parsed);
                }
                else
                {
                    _logger.LogWarning(
                        "winget export wrote no file (exit {Exit}). Catalogue matching needs package ids, " +
                        "so the server will believe this machine has no managed software. Output: {Output}",
                        result.ExitCode, Truncate(result.Output));
                }
            }
            finally
            {
                try { File.Delete(exportPath); } catch { /* best effort */ }
            }
        });

        // Silence here is what turned a broken probe into a policy that
        // reinstalled the same package every hour: say so out loud.
        if (wingetEntries == 0)
        {
            _logger.LogWarning(
                "No winget packages detected. If this machine does have winget apps, the export failed — " +
                "the catalogue matches on package id, so policies cannot see them.");
        }
        else
        {
            // Which of them are behind is a question only this machine can
            // answer; the server knows the catalogue, not what shipped today.
            await ProbeAsync("winget upgrade", async () =>
            {
                var result = await _processRunner.RunAsync(_wingetPath(),
                    ["upgrade", "--include-unknown", "--accept-source-agreements", "--disable-interactivity"],
                    TimeSpan.FromMinutes(3), ct);

                // Exits non-zero when nothing is upgradable — not a failure.
                var available = WingetUpgradeParser.Parse(result.Output);

                foreach (var entry in entries.Where(e => e.Source == "winget"))
                {
                    if (available.TryGetValue(entry.Name, out var newer))
                    {
                        entry.AvailableVersion = newer;
                    }
                }

                _logger.LogInformation("{Count} winget package(s) have an upgrade available.", available.Count);
            });
        }

        return entries
            .Where(e => !string.IsNullOrWhiteSpace(e.Name))
            .DistinctBy(e => (e.Source, e.Name, e.Version))
            .Take(MaxEntries)
            .ToList();
    }

    private static IEnumerable<SoftwareEntry> ScanHive(RegistryKey hive)
    {
        foreach (var path in UninstallKeyPaths)
        {
            using var uninstall = hive.OpenSubKey(path);
            if (uninstall is null)
            {
                continue;
            }

            foreach (var subKeyName in uninstall.GetSubKeyNames())
            {
                using var app = uninstall.OpenSubKey(subKeyName);
                if (app is null)
                {
                    continue;
                }

                var name = app.GetValue("DisplayName") as string;
                if (string.IsNullOrWhiteSpace(name))
                {
                    continue;
                }

                // Hidden system components are not user-facing software.
                if (app.GetValue("SystemComponent") is int sc && sc == 1)
                {
                    continue;
                }

                var isMsi = app.GetValue("WindowsInstaller") is int wi && wi == 1;

                yield return new SoftwareEntry
                {
                    Name = name.Trim(),
                    Version = (app.GetValue("DisplayVersion") as string)?.Trim(),
                    Publisher = (app.GetValue("Publisher") as string)?.Trim(),
                    Source = isMsi ? "msi" : "registry",
                };
            }
        }
    }

    /// <summary>Parses `choco list --limit-output` lines: "name|version".</summary>
    public static IReadOnlyList<SoftwareEntry> ParseChocoOutput(string output)
    {
        var entries = new List<SoftwareEntry>();
        foreach (var line in output.Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            var parts = line.Split('|');
            if (parts.Length == 2 && !string.IsNullOrWhiteSpace(parts[0]))
            {
                entries.Add(new SoftwareEntry
                {
                    Name = parts[0].Trim(),
                    Version = parts[1].Trim(),
                    Source = "choco",
                });
            }
        }

        return entries;
    }

    /// <summary>Parses winget export JSON into entries keyed by package
    /// identifier (exactly matches catalogue winget IDs).</summary>
    public static IReadOnlyList<SoftwareEntry> ParseWingetExport(string json)
    {
        var entries = new List<SoftwareEntry>();
        using var document = JsonDocument.Parse(json);

        if (!document.RootElement.TryGetProperty("Sources", out var sources))
        {
            return entries;
        }

        foreach (var source in sources.EnumerateArray())
        {
            if (!source.TryGetProperty("Packages", out var packages))
            {
                continue;
            }

            foreach (var package in packages.EnumerateArray())
            {
                if (!package.TryGetProperty("PackageIdentifier", out var id))
                {
                    continue;
                }

                entries.Add(new SoftwareEntry
                {
                    Name = id.GetString() ?? string.Empty,
                    Version = package.TryGetProperty("Version", out var version) ? version.GetString() : null,
                    Source = "winget",
                });
            }
        }

        return entries;
    }

    private static string Truncate(string? value)
        => value is null ? string.Empty : value.Length <= 500 ? value : value[..500];

    private void Probe(string what, Action probe)
    {
        try
        {
            probe();
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Software probe '{Probe}' failed", what);
        }
    }

    private async Task ProbeAsync(string what, Func<Task> probe)
    {
        try
        {
            await probe();
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Software probe '{Probe}' failed", what);
        }
    }
}
