using System.Net.Http.Json;
using Microsoft.Extensions.Logging;
using PioDeploy.Agent.Models;

namespace PioDeploy.Agent.Services;

public interface IApiClient
{
    Task<RegisterResponse?> RegisterAsync(RegisterRequest request, CancellationToken ct);
    Task<HeartbeatResponse?> HeartbeatAsync(HeartbeatRequest request, CancellationToken ct);
    Task<bool> SendInventoryAsync(InventoryRequest request, CancellationToken ct);
    Task<bool> SendSoftwareAsync(SoftwareRequest request, CancellationToken ct);
    Task<IReadOnlyList<JobPayload>> ClaimJobsAsync(string agentUuid, CancellationToken ct);
    Task<bool> ReportJobResultAsync(long jobId, JobResultRequest result, CancellationToken ct);
    Task<BrowserPolicyDocument?> GetBrowserPoliciesAsync(string agentUuid, CancellationToken ct);
    Task<bool> ReportBrowserPolicyResultsAsync(BrowserPolicyResultsRequest request, CancellationToken ct);
}

/// <summary>Typed HTTP client for the PioDeploy agent API. Authentication is
/// the project API key on every request; transport errors surface as null /
/// false so the worker's retry policy decides what to do.</summary>
public sealed class ApiClient : IApiClient
{
    private readonly HttpClient _http;
    private readonly ILogger<ApiClient> _logger;

    public ApiClient(HttpClient http, ILogger<ApiClient> logger)
    {
        _http = http;
        _logger = logger;
    }

    public async Task<RegisterResponse?> RegisterAsync(RegisterRequest request, CancellationToken ct)
    {
        var response = await _http.PostAsJsonAsync("api/v1/agent/register", request, ct);
        if (!response.IsSuccessStatusCode)
        {
            _logger.LogWarning("Register failed: {Status} {Body}",
                (int)response.StatusCode, await response.Content.ReadAsStringAsync(ct));
            return null;
        }

        return await response.Content.ReadFromJsonAsync<RegisterResponse>(cancellationToken: ct);
    }

    public async Task<HeartbeatResponse?> HeartbeatAsync(HeartbeatRequest request, CancellationToken ct)
    {
        var response = await _http.PostAsJsonAsync("api/v1/agent/heartbeat", request, ct);

        if (response.StatusCode == System.Net.HttpStatusCode.NotFound)
        {
            // Server no longer knows this agent — trigger re-registration.
            _logger.LogWarning("Heartbeat got 404 — the agent must re-register.");
            throw new AgentNotRegisteredException();
        }

        if (!response.IsSuccessStatusCode)
        {
            _logger.LogWarning("Heartbeat failed: {Status}", (int)response.StatusCode);
            return null;
        }

        return await response.Content.ReadFromJsonAsync<HeartbeatResponse>(cancellationToken: ct);
    }

    public async Task<bool> SendInventoryAsync(InventoryRequest request, CancellationToken ct)
    {
        var response = await _http.PostAsJsonAsync("api/v1/agent/inventory", request, ct);
        if (!response.IsSuccessStatusCode)
        {
            _logger.LogWarning("Inventory upload failed: {Status}", (int)response.StatusCode);
        }

        return response.IsSuccessStatusCode;
    }

    public async Task<bool> SendSoftwareAsync(SoftwareRequest request, CancellationToken ct)
    {
        var response = await _http.PostAsJsonAsync("api/v1/agent/software", request, ct);
        if (!response.IsSuccessStatusCode)
        {
            _logger.LogWarning("Software inventory upload failed: {Status}", (int)response.StatusCode);
        }

        return response.IsSuccessStatusCode;
    }

    public async Task<IReadOnlyList<JobPayload>> ClaimJobsAsync(string agentUuid, CancellationToken ct)
    {
        var response = await _http.PostAsJsonAsync("api/v1/agent/jobs", new { agent_uuid = agentUuid }, ct);
        if (!response.IsSuccessStatusCode)
        {
            _logger.LogWarning("Job claim failed: {Status}", (int)response.StatusCode);
            return [];
        }

        var payload = await response.Content.ReadFromJsonAsync<JobsResponse>(cancellationToken: ct);

        return payload?.Jobs ?? [];
    }

    public async Task<BrowserPolicyDocument?> GetBrowserPoliciesAsync(string agentUuid, CancellationToken ct)
    {
        var response = await _http.PostAsJsonAsync("api/v1/agent/browser-policies", new { agent_uuid = agentUuid }, ct);
        if (!response.IsSuccessStatusCode)
        {
            _logger.LogWarning("Browser policy fetch failed: {Status}", (int)response.StatusCode);
            return null;
        }

        return await response.Content.ReadFromJsonAsync<BrowserPolicyDocument>(cancellationToken: ct);
    }

    public async Task<bool> ReportBrowserPolicyResultsAsync(BrowserPolicyResultsRequest request, CancellationToken ct)
    {
        var response = await _http.PostAsJsonAsync("api/v1/agent/browser-policies/results", request, ct);
        if (!response.IsSuccessStatusCode)
        {
            _logger.LogWarning("Browser policy result report failed: {Status}", (int)response.StatusCode);
        }

        return response.IsSuccessStatusCode;
    }

    public async Task<bool> ReportJobResultAsync(long jobId, JobResultRequest result, CancellationToken ct)
    {
        var response = await _http.PostAsJsonAsync($"api/v1/agent/jobs/{jobId}/result", result, ct);
        if (!response.IsSuccessStatusCode)
        {
            _logger.LogWarning("Job result report failed for #{Job}: {Status}", jobId, (int)response.StatusCode);
        }

        return response.IsSuccessStatusCode;
    }
}

public sealed class AgentNotRegisteredException : Exception
{
}
