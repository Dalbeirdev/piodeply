using Microsoft.Extensions.Logging;
using PioDeploy.Agent.Installers;
using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Services;

public interface IInstallerEngine
{
    Task<InstallResult> ExecuteAsync(JobPayload job, CancellationToken ct);
}

/// <summary>Resolves the right installer strategy for a job and executes it.
/// Never throws — every outcome becomes an InstallResult the server can log.</summary>
public sealed class InstallerEngine : IInstallerEngine
{
    private readonly IEnumerable<IInstaller> _installers;
    private readonly ILogger<InstallerEngine> _logger;

    public InstallerEngine(IEnumerable<IInstaller> installers, ILogger<InstallerEngine> logger)
    {
        _installers = installers;
        _logger = logger;
    }

    public async Task<InstallResult> ExecuteAsync(JobPayload job, CancellationToken ct)
    {
        var installer = _installers.FirstOrDefault(i => i.Supports(job.InstallerType));
        if (installer is null)
        {
            return InstallResult.Fail($"No installer available for type '{job.InstallerType}'.");
        }

        _logger.LogInformation("Job #{Job}: {Action} {Package} via {Type}",
            job.JobId, job.Action, job.Package, job.InstallerType);

        try
        {
            var result = await installer.ExecuteAsync(job, ct);

            _logger.LogInformation("Job #{Job}: {Outcome} (exit {Exit})",
                job.JobId, result.Success ? "succeeded" : "FAILED: " + result.FailureReason, result.ExitCode);

            return result;
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw; // shutdown — let the worker decide
        }
        catch (Exception ex)
        {
            _logger.LogError(ex, "Job #{Job}: installer crashed", job.JobId);

            return InstallResult.Fail($"Installer crashed: {ex.GetType().Name}: {ex.Message}");
        }
    }
}
