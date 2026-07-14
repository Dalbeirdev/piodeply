namespace PioDeploy.Agent.Services;

public interface IAgentIdentity
{
    /// <summary>Stable per-machine agent UUID, created once and persisted.</summary>
    string GetAgentUuid();
}

/// <summary>Persists the agent UUID under ProgramData so reinstalls and
/// service restarts keep the same server-side computer record.</summary>
public sealed class AgentIdentityProvider : IAgentIdentity
{
    private readonly string _idFilePath;
    private string? _cached;

    public AgentIdentityProvider(string? baseDirectory = null)
    {
        var root = baseDirectory ?? Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "PioDeploy");
        _idFilePath = Path.Combine(root, "agent-id");
    }

    public string GetAgentUuid()
    {
        if (_cached is not null)
        {
            return _cached;
        }

        if (File.Exists(_idFilePath))
        {
            var existing = File.ReadAllText(_idFilePath).Trim();
            if (Guid.TryParse(existing, out var parsed))
            {
                return _cached = parsed.ToString();
            }
        }

        var uuid = Guid.NewGuid().ToString();
        Directory.CreateDirectory(Path.GetDirectoryName(_idFilePath)!);
        File.WriteAllText(_idFilePath, uuid);

        return _cached = uuid;
    }
}
