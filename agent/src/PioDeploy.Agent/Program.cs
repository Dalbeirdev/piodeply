using PioDeploy.Agent;
using PioDeploy.Agent.Services;

var builder = Host.CreateApplicationBuilder(args);

// Run as a Windows service when installed; console when run interactively.
builder.Services.AddWindowsService(options => options.ServiceName = "PioDeployAgent");

builder.Logging.AddProvider(new RollingFileLoggerProvider());

var serverUrl = builder.Configuration["PioDeploy:ServerUrl"]
    ?? throw new InvalidOperationException("PioDeploy:ServerUrl is not configured.");
var apiKey = builder.Configuration["PioDeploy:ApiKey"]
    ?? throw new InvalidOperationException("PioDeploy:ApiKey is not configured.");

builder.Services.AddHttpClient<IApiClient, ApiClient>(client =>
{
    client.BaseAddress = new Uri(serverUrl.TrimEnd('/') + '/');
    client.DefaultRequestHeaders.Add("X-Api-Key", apiKey);
    client.DefaultRequestHeaders.Add("User-Agent", "PioDeployAgent/1.0");
    client.Timeout = TimeSpan.FromSeconds(30);
});

builder.Services.AddSingleton<IAgentIdentity, AgentIdentityProvider>();
builder.Services.AddSingleton<IInventoryCollector, WindowsInventoryCollector>();
builder.Services.AddHostedService<Worker>();

var host = builder.Build();
host.Run();
