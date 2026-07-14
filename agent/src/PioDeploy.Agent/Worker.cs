using PioDeploy.Agent.Models;
using PioDeploy.Agent.Services;

namespace PioDeploy.Agent;

/// <summary>Agent main loop: ensure registration, then heartbeat on the
/// server-directed interval, refreshing full inventory periodically.
/// Network failures back off exponentially and never crash the service.</summary>
public sealed class Worker : BackgroundService
{
    private static readonly string AgentVersion =
        typeof(Worker).Assembly.GetName().Version?.ToString(3) ?? "0.0.0";

    private readonly IApiClient _api;
    private readonly IAgentIdentity _identity;
    private readonly IInventoryCollector _inventory;
    private readonly IInstallerEngine _engine;
    private readonly ILogger<Worker> _logger;

    private int _heartbeatSeconds = 60;
    private readonly int _inventoryEveryBeats = 60; // full refresh ~hourly

    public Worker(
        IApiClient api,
        IAgentIdentity identity,
        IInventoryCollector inventory,
        IInstallerEngine engine,
        ILogger<Worker> logger)
    {
        _api = api;
        _identity = identity;
        _inventory = inventory;
        _engine = engine;
        _logger = logger;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        _logger.LogInformation("PioDeploy agent {Version} starting; agent id {Uuid}",
            AgentVersion, _identity.GetAgentUuid());

        await RegisterWithRetryAsync(stoppingToken);

        var beats = 0;
        var backoff = TimeSpan.FromSeconds(_heartbeatSeconds);

        while (!stoppingToken.IsCancellationRequested)
        {
            try
            {
                var response = await _api.HeartbeatAsync(new HeartbeatRequest
                {
                    AgentUuid = _identity.GetAgentUuid(),
                    AgentVersion = AgentVersion,
                }, stoppingToken);

                if (response is not null)
                {
                    _heartbeatSeconds = Math.Clamp(response.HeartbeatSeconds, 15, 3600);
                    backoff = TimeSpan.FromSeconds(_heartbeatSeconds);

                    if (response.PendingJobs > 0)
                    {
                        _logger.LogInformation("{Jobs} job(s) pending on server", response.PendingJobs);
                        await ProcessJobsAsync(stoppingToken);
                    }
                }
                else
                {
                    backoff = Grow(backoff);
                }

                if (++beats % _inventoryEveryBeats == 0)
                {
                    await SendInventoryAsync(stoppingToken);
                }
            }
            catch (AgentNotRegisteredException)
            {
                await RegisterWithRetryAsync(stoppingToken);
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Heartbeat cycle failed; backing off {Backoff}s", backoff.TotalSeconds);
                backoff = Grow(backoff);
            }

            await SafeDelay(backoff, stoppingToken);
        }

        _logger.LogInformation("PioDeploy agent stopping.");
    }

    private async Task RegisterWithRetryAsync(CancellationToken ct)
    {
        var delay = TimeSpan.FromSeconds(5);

        while (!ct.IsCancellationRequested)
        {
            try
            {
                var response = await _api.RegisterAsync(new RegisterRequest
                {
                    AgentUuid = _identity.GetAgentUuid(),
                    AgentVersion = AgentVersion,
                    Inventory = _inventory.Collect(),
                }, ct);

                if (response is not null)
                {
                    _heartbeatSeconds = Math.Clamp(response.HeartbeatSeconds, 15, 3600);
                    _logger.LogInformation(
                        "Registered as computer #{Id} in project '{Project}' (heartbeat {Beat}s)",
                        response.ComputerId, response.Project, _heartbeatSeconds);
                    return;
                }
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                return;
            }
            catch (Exception ex)
            {
                _logger.LogWarning(ex, "Registration attempt failed");
            }

            _logger.LogInformation("Retrying registration in {Delay}s", delay.TotalSeconds);
            await SafeDelay(delay, ct);
            delay = Grow(delay);
        }
    }

    /// <summary>Claims pending jobs and executes them sequentially, reporting
    /// each result. Keeps draining until the server has nothing left.</summary>
    private async Task ProcessJobsAsync(CancellationToken ct)
    {
        for (var batch = 0; batch < 10 && !ct.IsCancellationRequested; batch++)
        {
            var jobs = await _api.ClaimJobsAsync(_identity.GetAgentUuid(), ct);
            if (jobs.Count == 0)
            {
                return;
            }

            foreach (var job in jobs)
            {
                if (ct.IsCancellationRequested)
                {
                    return;
                }

                var result = await _engine.ExecuteAsync(job, ct);

                await _api.ReportJobResultAsync(job.JobId, new JobResultRequest
                {
                    AgentUuid = _identity.GetAgentUuid(),
                    Success = result.Success,
                    ExitCode = result.ExitCode,
                    OutputLog = Truncate(result.Log, 60_000),
                    FailureReason = result.FailureReason,
                }, ct);
            }
        }
    }

    private static string? Truncate(string? value, int max)
        => value is null || value.Length <= max ? value : value[..max];

    private async Task SendInventoryAsync(CancellationToken ct)
    {
        try
        {
            var ok = await _api.SendInventoryAsync(new InventoryRequest
            {
                AgentUuid = _identity.GetAgentUuid(),
                Inventory = _inventory.Collect(),
            }, ct);

            if (ok)
            {
                _logger.LogInformation("Inventory refreshed.");
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Inventory refresh failed; will retry next cycle");
        }
    }

    private static TimeSpan Grow(TimeSpan current)
        => TimeSpan.FromSeconds(Math.Min(current.TotalSeconds * 2, 900)); // cap 15 min

    private static async Task SafeDelay(TimeSpan delay, CancellationToken ct)
    {
        try
        {
            await Task.Delay(delay, ct);
        }
        catch (OperationCanceledException)
        {
        }
    }
}
