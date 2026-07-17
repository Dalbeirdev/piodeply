using System.Text.Json.Serialization;

namespace PioDeploy.Agent.Models;

/// <summary>System inventory as reported to the server. Property names map
/// 1:1 to the API's snake_case contract.</summary>
public sealed class InventoryPayload
{
    [JsonPropertyName("hostname")] public string Hostname { get; set; } = string.Empty;
    [JsonPropertyName("serial_number")] public string? SerialNumber { get; set; }
    [JsonPropertyName("manufacturer")] public string? Manufacturer { get; set; }
    [JsonPropertyName("model")] public string? Model { get; set; }
    [JsonPropertyName("os_name")] public string? OsName { get; set; }
    [JsonPropertyName("os_version")] public string? OsVersion { get; set; }
    [JsonPropertyName("windows_build")] public string? WindowsBuild { get; set; }
    [JsonPropertyName("cpu")] public string? Cpu { get; set; }
    [JsonPropertyName("ram_bytes")] public long? RamBytes { get; set; }
    [JsonPropertyName("disk_total_bytes")] public long? DiskTotalBytes { get; set; }
    [JsonPropertyName("disk_free_bytes")] public long? DiskFreeBytes { get; set; }
    [JsonPropertyName("private_ip")] public string? PrivateIp { get; set; }
    [JsonPropertyName("mac_address")] public string? MacAddress { get; set; }
    [JsonPropertyName("secure_boot")] public bool? SecureBoot { get; set; }
    [JsonPropertyName("tpm_enabled")] public bool? TpmEnabled { get; set; }
    [JsonPropertyName("tpm_version")] public string? TpmVersion { get; set; }
}

public sealed class RegisterRequest
{
    [JsonPropertyName("agent_uuid")] public string AgentUuid { get; set; } = string.Empty;
    [JsonPropertyName("agent_version")] public string? AgentVersion { get; set; }
    [JsonPropertyName("inventory")] public InventoryPayload Inventory { get; set; } = new();
}

public sealed class RegisterResponse
{
    [JsonPropertyName("computer_id")] public long ComputerId { get; set; }
    [JsonPropertyName("hostname")] public string? Hostname { get; set; }
    [JsonPropertyName("project")] public string? Project { get; set; }
    [JsonPropertyName("heartbeat_seconds")] public int HeartbeatSeconds { get; set; } = 60;
}

public sealed class HeartbeatRequest
{
    [JsonPropertyName("agent_uuid")] public string AgentUuid { get; set; } = string.Empty;
    [JsonPropertyName("agent_version")] public string? AgentVersion { get; set; }
}

public sealed class HeartbeatResponse
{
    [JsonPropertyName("status")] public string? Status { get; set; }
    [JsonPropertyName("pending_jobs")] public int PendingJobs { get; set; }
    [JsonPropertyName("heartbeat_seconds")] public int HeartbeatSeconds { get; set; } = 60;

    /// <summary>The version the server wants this agent to be on, and where to
    /// fetch it. An agent already on it ignores both; an older one updates
    /// itself, so a machine is upgraded once and never touched by hand again.</summary>
    [JsonPropertyName("latest_agent_version")] public string? LatestAgentVersion { get; set; }
    [JsonPropertyName("bundle_url")] public string? BundleUrl { get; set; }
}

public sealed class InventoryRequest
{
    [JsonPropertyName("agent_uuid")] public string AgentUuid { get; set; } = string.Empty;
    [JsonPropertyName("inventory")] public InventoryPayload Inventory { get; set; } = new();
}
