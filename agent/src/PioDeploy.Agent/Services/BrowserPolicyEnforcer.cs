using System.Diagnostics;
using System.Text.Json;
using Microsoft.Extensions.Logging;
using Microsoft.Win32;
using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Services;

public interface IBrowserPolicyEnforcer
{
    /// <summary>Applies the desired-state document (idempotently), rolls
    /// back settings no longer managed, verifies, and returns per-policy
    /// per-browser results for the server.</summary>
    List<BrowserPolicyResultReport> Enforce(BrowserPolicyDocument document);
}

/// <summary>Writes Chromium-family enterprise policies to
/// HKLM\SOFTWARE\Policies and Firefox policies to distribution\policies.json.
/// A local manifest records everything managed, so removed policies are
/// cleaned up on the next run. Never throws: failures become "error"
/// results the server can alert on.</summary>
public sealed class BrowserPolicyEnforcer : IBrowserPolicyEnforcer
{
    private static readonly string ManifestPath = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
        "PioDeploy", "browser-policy-manifest.json");

    private static readonly Dictionary<string, string[]> ProcessNames = new(StringComparer.OrdinalIgnoreCase)
    {
        ["chrome"] = ["chrome"],
        ["edge"] = ["msedge"],
        ["firefox"] = ["firefox"],
        ["brave"] = ["brave"],
        ["opera"] = ["opera"],
    };

    private readonly ILogger<BrowserPolicyEnforcer> _logger;

    public BrowserPolicyEnforcer(ILogger<BrowserPolicyEnforcer> logger)
    {
        _logger = logger;
    }

    public List<BrowserPolicyResultReport> Enforce(BrowserPolicyDocument document)
    {
        var results = new List<BrowserPolicyResultReport>();
        var previous = LoadManifest();
        var manifest = new BrowserPolicyManifest();
        var firefoxSetKeys = new Dictionary<string, bool>(StringComparer.OrdinalIgnoreCase);
        var firefoxReports = new List<(long PolicyId, string Key)>();

        foreach (var policy in document.Policies)
        {
            foreach (var (browser, operation) in policy.Operations)
            {
                switch (operation.Kind)
                {
                    case "registry":
                        results.Add(ApplyRegistry(policy.PolicyId, browser, operation, manifest));
                        break;

                    case "firefox_json":
                        // Firefox writes are batched into one file update below.
                        firefoxSetKeys[operation.Key!] = operation.BoolValue();
                        firefoxReports.Add((policy.PolicyId, operation.Key!));
                        manifest.FirefoxKeys.Add(operation.Key!);
                        break;

                    default:
                        results.Add(new BrowserPolicyResultReport
                        {
                            PolicyId = policy.PolicyId,
                            Browser = browser,
                            Status = "unsupported",
                            Detail = "This browser has no enterprise policy support for this setting.",
                        });
                        break;
                }
            }
        }

        // Roll back registry values that are no longer managed.
        foreach (var stale in BrowserPolicyPlanner.RegistryValuesToRemove(previous, manifest.RegistryValues))
        {
            TryDeleteRegistryValue(stale);
        }

        // One Firefox file write covering all set + removed keys.
        var firefoxRemovals = BrowserPolicyPlanner.FirefoxKeysToRemove(previous, manifest.FirefoxKeys);
        results.AddRange(ApplyFirefox(firefoxSetKeys, firefoxRemovals, firefoxReports));

        SaveManifest(manifest);

        return results;
    }

    /* ─────────────────────── Registry (Chromium family) ─────────────── */

    private BrowserPolicyResultReport ApplyRegistry(long policyId, string browser, BrowserOperation operation, BrowserPolicyManifest manifest)
    {
        var report = new BrowserPolicyResultReport { PolicyId = policyId, Browser = browser };

        try
        {
            manifest.RegistryValues.Add(new ManagedRegistryValue { Path = operation.Path!, Name = operation.Name! });

            if (!IsBrowserInstalled(browser))
            {
                report.Status = "not_installed";
                report.Detail = "Browser not detected on this machine.";
                return report;
            }

            var desired = operation.RegistryValue();

            using var key = Registry.LocalMachine.CreateSubKey(operation.Path!, writable: true)
                ?? throw new InvalidOperationException($"Cannot open HKLM\\{operation.Path}");

            var before = key.GetValue(operation.Name!);
            var changed = before is not int current || current != desired;

            if (changed)
            {
                key.SetValue(operation.Name!, desired, RegistryValueKind.DWord);
                _logger.LogInformation("Browser policy: HKLM\\{Path}\\{Name} = {Value} (was {Before})",
                    operation.Path, operation.Name, desired, before ?? "unset");
            }

            // Verify by reading back.
            var after = key.GetValue(operation.Name!);
            var correct = after is int verified && verified == desired;

            report.OldValue = before?.ToString();
            report.NewValue = after?.ToString();
            report.Status = BrowserPolicyPlanner.StatusFor(
                installed: true,
                supported: true,
                valueCorrect: correct,
                changedThisRun: changed,
                browserRunning: IsBrowserRunning(browser));
            if (!correct)
            {
                report.Detail = "Verification failed: value did not persist.";
            }
        }
        catch (Exception ex)
        {
            report.Status = "error";
            report.Detail = Truncate(ex.Message, 450);
            _logger.LogWarning(ex, "Browser policy registry apply failed for {Browser}", browser);
        }

        return report;
    }

    private void TryDeleteRegistryValue(ManagedRegistryValue value)
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(value.Path, writable: true);
            if (key?.GetValue(value.Name) is not null)
            {
                key.DeleteValue(value.Name);
                _logger.LogInformation("Browser policy rollback: removed HKLM\\{Path}\\{Name}", value.Path, value.Name);
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Browser policy rollback failed for HKLM\\{Path}\\{Name}", value.Path, value.Name);
        }
    }

    /* ─────────────────────── Firefox policies.json ───────────────────── */

    private IEnumerable<BrowserPolicyResultReport> ApplyFirefox(
        Dictionary<string, bool> setKeys,
        List<string> removeKeys,
        List<(long PolicyId, string Key)> reports)
    {
        if (setKeys.Count == 0 && removeKeys.Count == 0)
        {
            yield break;
        }

        var installDir = FirefoxInstallDir();

        if (installDir is null)
        {
            foreach (var (policyId, _) in reports)
            {
                yield return new BrowserPolicyResultReport
                {
                    PolicyId = policyId, Browser = "firefox",
                    Status = "not_installed", Detail = "Firefox not detected on this machine.",
                };
            }

            yield break;
        }

        string status;
        string? detail = null;
        var changed = false;

        try
        {
            var path = Path.Combine(installDir, "distribution", "policies.json");
            Directory.CreateDirectory(Path.GetDirectoryName(path)!);

            var existing = File.Exists(path) ? File.ReadAllText(path) : null;
            var merged = BrowserPolicyPlanner.MergeFirefoxPolicies(existing, setKeys, removeKeys);

            if (merged is not null)
            {
                File.WriteAllText(path, merged);
                changed = true;
                _logger.LogInformation("Browser policy: updated {Path} ({Set} set, {Removed} removed)",
                    path, setKeys.Count, removeKeys.Count);
            }

            status = BrowserPolicyPlanner.StatusFor(
                installed: true, supported: true, valueCorrect: true,
                changedThisRun: changed, browserRunning: IsBrowserRunning("firefox"));
        }
        catch (Exception ex)
        {
            status = "error";
            detail = Truncate(ex.Message, 450);
            _logger.LogWarning(ex, "Firefox policies.json update failed");
        }

        foreach (var (policyId, _) in reports)
        {
            yield return new BrowserPolicyResultReport
            {
                PolicyId = policyId, Browser = "firefox", Status = status, Detail = detail,
            };
        }
    }

    /* ─────────────────────── Detection helpers ───────────────────────── */

    private static bool IsBrowserInstalled(string browser) => browser switch
    {
        "chrome" => AppPathExists("chrome.exe"),
        "edge" => AppPathExists("msedge.exe"),
        "firefox" => FirefoxInstallDir() is not null,
        "brave" => AppPathExists("brave.exe"),
        "opera" => AppPathExists("opera.exe") || AppPathExists("launcher.exe"),
        _ => false,
    };

    private static bool AppPathExists(string exe)
    {
        foreach (var hive in new[] { Registry.LocalMachine, Registry.CurrentUser })
        {
            using var key = hive.OpenSubKey($@"SOFTWARE\Microsoft\Windows\CurrentVersion\App Paths\{exe}");
            if (key?.GetValue(null) is string path && File.Exists(path))
            {
                return true;
            }
        }

        return false;
    }

    private static string? FirefoxInstallDir()
    {
        using var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Mozilla\Mozilla Firefox");
        if (key?.GetValue("CurrentVersion") is string version)
        {
            using var main = Registry.LocalMachine.OpenSubKey($@"SOFTWARE\Mozilla\Mozilla Firefox\{version}\Main");
            if (main?.GetValue("Install Directory") is string dir && Directory.Exists(dir))
            {
                return dir;
            }
        }

        var fallback = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "Mozilla Firefox");

        return File.Exists(Path.Combine(fallback, "firefox.exe")) ? fallback : null;
    }

    private static bool IsBrowserRunning(string browser)
    {
        if (!ProcessNames.TryGetValue(browser, out var names))
        {
            return false;
        }

        try
        {
            return names.Any(name => Process.GetProcessesByName(name).Length > 0);
        }
        catch
        {
            return false;
        }
    }

    /* ─────────────────────── Manifest ────────────────────────────────── */

    private BrowserPolicyManifest LoadManifest()
    {
        try
        {
            if (File.Exists(ManifestPath))
            {
                return JsonSerializer.Deserialize<BrowserPolicyManifest>(File.ReadAllText(ManifestPath))
                    ?? new BrowserPolicyManifest();
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Could not read browser-policy manifest; starting fresh");
        }

        return new BrowserPolicyManifest();
    }

    private void SaveManifest(BrowserPolicyManifest manifest)
    {
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(ManifestPath)!);
            File.WriteAllText(ManifestPath, JsonSerializer.Serialize(manifest));
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Could not persist browser-policy manifest");
        }
    }

    private static string Truncate(string value, int max)
        => value.Length <= max ? value : value[..max];
}
