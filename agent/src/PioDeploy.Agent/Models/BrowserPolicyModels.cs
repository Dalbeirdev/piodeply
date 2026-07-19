using System.Text.Json.Serialization;

namespace PioDeploy.Agent.Models;

/// <summary>The complete browser-policy desired state for this machine.</summary>
public sealed class BrowserPolicyDocument
{
    [JsonPropertyName("policies")] public List<BrowserPolicyItem> Policies { get; set; } = [];
}

public sealed class BrowserPolicyItem
{
    [JsonPropertyName("policy_id")] public long PolicyId { get; set; }
    [JsonPropertyName("type")] public string Type { get; set; } = string.Empty;
    [JsonPropertyName("action")] public string Action { get; set; } = "disable";

    /// <summary>Browser value → operation. Kinds: registry, registry_sz,
    /// registry_list, firefox_json, unsupported.</summary>
    [JsonPropertyName("operations")] public Dictionary<string, BrowserOperation> Operations { get; set; } = [];
}

public sealed class BrowserOperation
{
    [JsonPropertyName("kind")] public string Kind { get; set; } = string.Empty;

    // registry / registry_sz
    [JsonPropertyName("path")] public string? Path { get; set; }
    [JsonPropertyName("name")] public string? Name { get; set; }
    [JsonPropertyName("value")] public System.Text.Json.JsonElement? Value { get; set; }

    // registry_list — string entries written as numbered values "1".."N"
    // directly under Path (the Chromium list-policy convention).
    [JsonPropertyName("values")] public List<string>? Values { get; set; }

    // firefox_json
    [JsonPropertyName("key")] public string? Key { get; set; }

    public int RegistryValue() => Value?.GetInt32() ?? 0;

    public bool BoolValue() => Value?.GetBoolean() ?? false;

    public string StringValue() => Value?.GetString() ?? string.Empty;
}

public sealed class BrowserPolicyResultReport
{
    [JsonPropertyName("policy_id")] public long PolicyId { get; set; }
    [JsonPropertyName("browser")] public string Browser { get; set; } = string.Empty;
    [JsonPropertyName("status")] public string Status { get; set; } = "error";
    [JsonPropertyName("detail")] public string? Detail { get; set; }
    [JsonPropertyName("old_value")] public string? OldValue { get; set; }
    [JsonPropertyName("new_value")] public string? NewValue { get; set; }
}

public sealed class BrowserPolicyResultsRequest
{
    [JsonPropertyName("agent_uuid")] public string AgentUuid { get; set; } = string.Empty;
    [JsonPropertyName("results")] public List<BrowserPolicyResultReport> Results { get; set; } = [];
}

/// <summary>Everything the agent wrote last run, persisted locally so
/// settings removed from the server document can be rolled back.</summary>
public sealed class BrowserPolicyManifest
{
    [JsonPropertyName("registry_values")] public List<ManagedRegistryValue> RegistryValues { get; set; } = [];
    [JsonPropertyName("firefox_keys")] public List<string> FirefoxKeys { get; set; } = [];
}

public sealed class ManagedRegistryValue
{
    [JsonPropertyName("path")] public string Path { get; set; } = string.Empty;
    [JsonPropertyName("name")] public string Name { get; set; } = string.Empty;

    public override bool Equals(object? obj)
        => obj is ManagedRegistryValue other
           && string.Equals(Path, other.Path, StringComparison.OrdinalIgnoreCase)
           && string.Equals(Name, other.Name, StringComparison.OrdinalIgnoreCase);

    public override int GetHashCode()
        => HashCode.Combine(Path.ToUpperInvariant(), Name.ToUpperInvariant());
}
