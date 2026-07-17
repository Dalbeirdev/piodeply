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
    private readonly SelfUpdater _selfUpdater;
    private readonly IHostApplicationLifetime _lifetime;
    private readonly ILogger<Worker> _logger;

    private bool _updating;

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
        SelfUpdater selfUpdater,
        IHostApplicationLifetime lifetime,
        ILogger<Worker> logger)
    {
        _api = api;
        _identity = identity;
        _inventory = inventory;
        _software = software;
        _engine = engine;
        _browserPolicies = browserPolicies;
        _selfUpdater = selfUpdater;
        _lifetime = lifetime;
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

                    // A newer build on the server upgrades this machine with no
                    // one re-running a script. Checked before jobs, so an agent
                    // known to be superseded is replaced rather than kept busy.
                    if (await TrySelfUpdateAsync(response, stoppingToken))
                    {
                        _lifetime.StopApplication(); // let the helper swap us out
                        break;
                    }

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

    /// <summary>Replaces this agent with the server's version when it is newer.
    /// Returns true once a swap has been staged, so the caller stops the
    /// service and lets the detached helper take over.</summary>
    private async Task<bool> TrySelfUpdateAsync(HeartbeatResponse response, CancellationToken ct)
    {
        if (_updating || !SelfUpdatePlan.ShouldUpdate(AgentVersion, response.LatestAgentVersion, response.BundleUrl))
        {
            return false;
        }

        _updating = true; // never stage twice, even if the swap is slow to take
        _logger.LogInformation("Server offers agent {Version}; updating from {Current}.",
            response.LatestAgentVersion, AgentVersion);

        return await _selfUpdater.UpdateAsync(response.BundleUrl!, response.LatestAgentVersion!, ct);
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

            IReadOnlyList<SoftwareEntry>? software = null;

            foreach (var job in jobs)
            {
                if (ct.IsCancellationRequested)
                {
                    break; // still send the inventory gathered so far
                }

                var result = await _engine.ExecuteAsync(job, ct);

                // A job changes what is installed, so re-read the machine
                // rather than leave the server judging "already installed"
                // against an inventory up to an hour stale. Best-effort: a
                // version is nice, the result is not optional.
                software = await TryCollectSoftwareAsync(ct);

                await ReportResultAsync(job, result, software);

                // Scanning and installing are slow, and this loop runs inside
                // the heartbeat cycle — without this the agent stops checking
                // in and the portal shows it offline exactly while it is
                // busiest. Cheap, and it keeps last_seen_at honest.
                await SafeHeartbeatAsync(ct);
            }

            // Once per batch, not once per job: each send supersedes the last,
            // and a full inventory is up to 2500 rows.
            if (software is not null)
            {
                await SendSoftwareAsync(ct, software);
            }
        }
    }

    /// <summary>Reports an outcome. The work already happened on the machine,
    /// so this deliberately does not honour the shutdown token: losing the
    /// result strands the job in Running with nothing to reap it. Bounded so
    /// a hung server cannot block service stop indefinitely.</summary>
    private async Task ReportResultAsync(JobPayload job, InstallResult result, IReadOnlyList<SoftwareEntry>? software)
    {
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));

        try
        {
            await _api.ReportJobResultAsync(job.JobId, new JobResultRequest
            {
                AgentUuid = _identity.GetAgentUuid(),
                Success = result.Success,
                ExitCode = result.ExitCode,
                OutputLog = Truncate(result.Log, 60_000),
                FailureReason = result.FailureReason,
                InstalledVersion = software is null
                    ? null
                    : InstalledVersionResolver.Resolve(software, job),
            }, timeout.Token);
        }
        catch (Exception ex)
        {
            // Nothing more we can do: the server will show it Running until
            // an operator requeues it. Say so loudly rather than silently.
            _logger.LogError(ex, "Job {JobId} finished but its result could not be reported", job.JobId);
        }
    }

    /// <summary>A heartbeat that never disturbs job processing.</summary>
    private async Task SafeHeartbeatAsync(CancellationToken ct)
    {
        try
        {
            await _api.HeartbeatAsync(new HeartbeatRequest
            {
                AgentUuid = _identity.GetAgentUuid(),
                AgentVersion = AgentVersion,
            }, ct);
        }
        catch (Exception ex)
        {
            _logger.LogDebug(ex, "Mid-batch heartbeat failed; the next cycle will catch up");
        }
    }

    /// <summary>Collecting inventory must never turn a completed job into an
    /// unreported one — the result matters more than the version beside it.</summary>
    private async Task<IReadOnlyList<SoftwareEntry>?> TryCollectSoftwareAsync(CancellationToken ct)
    {
        try
        {
            return await _software.CollectAsync(ct);
        }
        catch (OperationCanceledException)
        {
            return null; // a service stop is not a fault worth shouting about
        }
        catch (Exception ex)
        {
            _logger.LogWarning(ex, "Post-job software scan failed; reporting result without a version");
            return null;
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

    /// <summary>Sends the software inventory, reusing an already-collected
    /// list when the caller has one (a scan is slow — winget export shells
    /// out — so a job should not pay for it twice).</summary>
    private async Task SendSoftwareAsync(CancellationToken ct, IReadOnlyList<SoftwareEntry>? collected = null)
    {
        try
        {
            var software = collected ?? await _software.CollectAsync(ct);

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
