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
    private readonly ISoftwareCollector _software;
    private readonly IInstallerEngine _engine;
    private readonly IBrowserPolicyEnforcer _browserPolicies;
    private readonly ILogger<Worker> _logger;

    private int _heartbeatSeconds = 60;
    private readonly int _inventoryEveryBeats = 60;      // full refresh ~hourly
    private readonly int _browserPolicyEveryBeats = 15;  // policy sync ~every 15 min

    public Worker(
        IApiClient api,
        IAgentIdentity identity,
        IInventoryCollector inventory,
        ISoftwareCollector software,
        IInstallerEngine engine,
        IBrowserPolicyEnforcer browserPolicies,
        ILogger<Worker> logger)
    {
        _api = api;
        _identity = identity;
        _inventory = inventory;
        _software = software;
        _engine = engine;
        _browserPolicies = browserPolicies;
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

                // First beat after start, then on the regular cadence.
                if (beats == 1 || beats % _browserPolicyEveryBeats == 0)
                {
                    await EnforceBrowserPoliciesAsync(stoppingToken);
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
                    await SendSoftwareAsync(ct); // initial software inventory
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

    /// <summary>Pulls the browser-policy desired state, applies it (with
    /// rollback of settings no longer managed) and reports compliance.</summary>
    private async Task EnforceBrowserPoliciesAsync(CancellationToken ct)
    {
        try
        {
            var document = await _api.GetBrowserPoliciesAsync(_identity.GetAgentUuid(), ct);
            if (document is null)
            {
                return; // transient fetch failure — next cycle retries
            }

            var results = _browserPolicies.Enforce(document);

            if (results.Count > 0)
            {
                await _api.ReportBrowserPolicyResultsAsync(new BrowserPolicyResultsRequest
                {
                    AgentUuid = _identity.GetAgentUuid(),
                    Results = results,
                }, ct);
            }

            _logger.LogInformation("Browser policies enforced: {Policies} policy item(s), {Results} result(s).",
                document.Policies.Count, results.Count);
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Browser policy enforcement failed; will retry next cycle");
        }
    }

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

            await SendSoftwareAsync(ct);
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Inventory refresh failed; will retry next cycle");
        }
    }

    private async Task SendSoftwareAsync(CancellationToken ct)
    {
        try
        {
            var software = await _software.CollectAsync(ct);

            var ok = await _api.SendSoftwareAsync(new SoftwareRequest
            {
                AgentUuid = _identity.GetAgentUuid(),
                Software = software,
            }, ct);

            if (ok)
            {
                _logger.LogInformation("Software inventory sent ({Count} entries).", software.Count);
            }
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Software inventory collection failed; will retry next cycle");
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
