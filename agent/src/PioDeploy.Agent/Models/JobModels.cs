using System.Text.Json.Serialization;

namespace PioDeploy.Agent.Models;

/// <summary>A deployment job as delivered by the server's claim endpoint.</summary>
public sealed class JobPayload
{
    [JsonPropertyName("job_id")] public long JobId { get; set; }
    [JsonPropertyName("action")] public string Action { get; set; } = "install";
    [JsonPropertyName("package")] public string? Package { get; set; }
    [JsonPropertyName("installer_type")] public string InstallerType { get; set; } = string.Empty;
    [JsonPropertyName("winget_id")] public string? WingetId { get; set; }
    [JsonPropertyName("choco_id")] public string? ChocoId { get; set; }
    [JsonPropertyName("version")] public string? Version { get; set; }
    [JsonPropertyName("installer_url")] public string? InstallerUrl { get; set; }
    [JsonPropertyName("sha256")] public string? Sha256 { get; set; }
    [JsonPropertyName("silent_args")] public string? SilentArgs { get; set; }
    [JsonPropertyName("uninstall_args")] public string? UninstallArgs { get; set; }
}

public sealed class JobsResponse
{
    [JsonPropertyName("jobs")] public List<JobPayload> Jobs { get; set; } = [];
}

public sealed class JobResultRequest
{
    [JsonPropertyName("agent_uuid")] public string AgentUuid { get; set; } = string.Empty;
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("exit_code")] public int? ExitCode { get; set; }
    [JsonPropertyName("output_log")] public string? OutputLog { get; set; }
    [JsonPropertyName("failure_reason")] public string? FailureReason { get; set; }

    /// <summary>What the package reports as installed once the job finished.
    /// Null when the package manager has no row for it (an uninstall, or a
    /// failed install) or the package carries no matchable id.</summary>
    [JsonPropertyName("installed_version")] public string? InstalledVersion { get; set; }
}

public sealed class SoftwareEntry
{
    [JsonPropertyName("name")] public string Name { get; set; } = string.Empty;
    [JsonPropertyName("version")] public string? Version { get; set; }

    /// <summary>What the package manager on this machine is offering, if
    /// anything. Only it knows — the server has no idea a newer Chrome
    /// shipped this morning.</summary>
    [JsonPropertyName("available_version")] public string? AvailableVersion { get; set; }

    [JsonPropertyName("publisher")] public string? Publisher { get; set; }
    [JsonPropertyName("source")] public string Source { get; set; } = "registry";
}

/// <summary>One machine-readiness self-check reported to the server.</summary>
public sealed class EnvironmentCheck
{
    [JsonPropertyName("key")] public string Key { get; set; } = string.Empty;
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("detail")] public string? Detail { get; set; }
}

public sealed class SoftwareRequest
{
    [JsonPropertyName("agent_uuid")] public string AgentUuid { get; set; } = string.Empty;
    [JsonPropertyName("software")] public IReadOnlyList<SoftwareEntry> Software { get; set; } = [];

    /// <summary>Readiness checks ride with the inventory they gate.</summary>
    [JsonPropertyName("environment")] public IReadOnlyList<EnvironmentCheck> Environment { get; set; } = [];
}

/// <summary>Outcome of one installer execution.</summary>
public sealed record InstallResult(bool Success, int? ExitCode, string Log, string? FailureReason)
{
    public static InstallResult Ok(int? exitCode, string log) => new(true, exitCode, log, null);

    public static InstallResult Fail(string reason, int? exitCode = null, string log = "")
        => new(false, exitCode, log, reason);
}
