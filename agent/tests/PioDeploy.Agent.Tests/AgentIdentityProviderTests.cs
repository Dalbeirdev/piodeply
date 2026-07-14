using PioDeploy.Agent.Services;

namespace PioDeploy.Agent.Tests;

public class AgentIdentityProviderTests : IDisposable
{
    private readonly string _tempDir = Path.Combine(Path.GetTempPath(), "piodeploy-tests-" + Guid.NewGuid());

    [Fact]
    public void Generates_A_Valid_Uuid_And_Persists_It()
    {
        var provider = new AgentIdentityProvider(_tempDir);

        var uuid = provider.GetAgentUuid();

        Assert.True(Guid.TryParse(uuid, out _));
        Assert.True(File.Exists(Path.Combine(_tempDir, "agent-id")));
    }

    [Fact]
    public void Returns_The_Same_Uuid_Across_Instances()
    {
        var first = new AgentIdentityProvider(_tempDir).GetAgentUuid();
        var second = new AgentIdentityProvider(_tempDir).GetAgentUuid();

        Assert.Equal(first, second);
    }

    [Fact]
    public void Replaces_A_Corrupted_Id_File()
    {
        Directory.CreateDirectory(_tempDir);
        File.WriteAllText(Path.Combine(_tempDir, "agent-id"), "not-a-uuid");

        var uuid = new AgentIdentityProvider(_tempDir).GetAgentUuid();

        Assert.True(Guid.TryParse(uuid, out _));
    }

    public void Dispose()
    {
        if (Directory.Exists(_tempDir))
        {
            Directory.Delete(_tempDir, recursive: true);
        }
    }
}
