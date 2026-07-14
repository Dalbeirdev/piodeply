using System.Text.Json;
using System.Text.Json.Nodes;
using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Services;

/// <summary>Pure decision logic for browser-policy enforcement, kept free
/// of registry/filesystem access so it is unit-testable.</summary>
public static class BrowserPolicyPlanner
{
    /// <summary>Registry values in the previous manifest that no policy
    /// wants any more — these get rolled back (deleted).</summary>
    public static List<ManagedRegistryValue> RegistryValuesToRemove(
        BrowserPolicyManifest previous,
        IReadOnlyCollection<ManagedRegistryValue> desired)
    {
        var wanted = new HashSet<ManagedRegistryValue>(desired);

        return previous.RegistryValues.Where(value => !wanted.Contains(value)).ToList();
    }

    /// <summary>Firefox policies.json keys in the previous manifest that are
    /// no longer managed.</summary>
    public static List<string> FirefoxKeysToRemove(
        BrowserPolicyManifest previous,
        IReadOnlyCollection<string> desiredKeys)
    {
        var wanted = new HashSet<string>(desiredKeys, StringComparer.OrdinalIgnoreCase);

        return previous.FirefoxKeys.Where(key => !wanted.Contains(key)).ToList();
    }

    /// <summary>Merges managed keys into an existing policies.json without
    /// disturbing anything else in the file. Returns null when the result
    /// is byte-for-byte what already exists (no write needed).</summary>
    public static string? MergeFirefoxPolicies(
        string? existingJson,
        IReadOnlyDictionary<string, bool> setKeys,
        IReadOnlyCollection<string> removeKeys)
    {
        JsonObject root;
        try
        {
            root = string.IsNullOrWhiteSpace(existingJson)
                ? new JsonObject()
                : JsonNode.Parse(existingJson) as JsonObject ?? new JsonObject();
        }
        catch (JsonException)
        {
            root = new JsonObject(); // corrupt file — rebuild the managed part
        }

        if (root["policies"] is not JsonObject policies)
        {
            policies = new JsonObject();
            root["policies"] = policies;
        }

        var changed = false;

        foreach (var (key, value) in setKeys)
        {
            var current = policies[key] as JsonValue;
            var isBool = current is not null
                && current.GetValueKind() is JsonValueKind.True or JsonValueKind.False;

            if (!isBool || current!.GetValue<bool>() != value)
            {
                policies[key] = value;
                changed = true;
            }
        }

        foreach (var key in removeKeys)
        {
            if (policies.ContainsKey(key))
            {
                policies.Remove(key);
                changed = true;
            }
        }

        return changed
            ? root.ToJsonString(new JsonSerializerOptions { WriteIndented = true })
            : null;
    }

    /// <summary>Collapse an apply outcome into a report status.</summary>
    public static string StatusFor(bool installed, bool supported, bool valueCorrect, bool changedThisRun, bool browserRunning)
    {
        if (!supported)
        {
            return "unsupported";
        }

        if (!installed)
        {
            return "not_installed";
        }

        if (!valueCorrect)
        {
            return "non_compliant";
        }

        return changedThisRun && browserRunning ? "pending_restart" : "compliant";
    }
}
