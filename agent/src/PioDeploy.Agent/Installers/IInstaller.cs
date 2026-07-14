using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Installers;

public interface IInstaller
{
    /// <summary>Installer-type discriminator this strategy handles
    /// (matches the server's InstallerType enum values).</summary>
    bool Supports(string installerType);

    Task<InstallResult> ExecuteAsync(JobPayload job, CancellationToken ct);
}
