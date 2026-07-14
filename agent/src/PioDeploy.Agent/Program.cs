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

// Plain client for installer downloads (no API key attached).
builder.Services.AddHttpClient("downloads", client =>
{
    client.Timeout = TimeSpan.FromMinutes(15);
    client.DefaultRequestHeaders.Add("User-Agent", "PioDeployAgent/1.0");
});

builder.Services.AddSingleton<IAgentIdentity, AgentIdentityProvider>();
builder.Services.AddSingleton<IInventoryCollector, WindowsInventoryCollector>();
builder.Services.AddSingleton<ISoftwareCollector, WindowsSoftwareCollector>();

// Installer engine + strategies
builder.Services.AddSingleton<IProcessRunner, ProcessRunner>();
builder.Services.AddSingleton<IChecksumVerifier, ChecksumVerifier>();
builder.Services.AddSingleton<IPackageDownloader, PackageDownloader>();
builder.Services.AddSingleton<PioDeploy.Agent.Installers.IInstaller, PioDeploy.Agent.Installers.WingetInstaller>();
builder.Services.AddSingleton<PioDeploy.Agent.Installers.IInstaller, PioDeploy.Agent.Installers.ChocoInstaller>();
builder.Services.AddSingleton<PioDeploy.Agent.Installers.IInstaller, PioDeploy.Agent.Installers.MsiInstaller>();
builder.Services.AddSingleton<PioDeploy.Agent.Installers.IInstaller, PioDeploy.Agent.Installers.ExeInstaller>();
builder.Services.AddSingleton<PioDeploy.Agent.Installers.IInstaller, PioDeploy.Agent.Installers.ZipInstaller>();
builder.Services.AddSingleton<PioDeploy.Agent.Installers.IInstaller, PioDeploy.Agent.Installers.MsixInstaller>();
builder.Services.AddSingleton<PioDeploy.Agent.Installers.IInstaller, PioDeploy.Agent.Installers.PowerShellInstaller>();
builder.Services.AddSingleton<IInstallerEngine, InstallerEngine>();
builder.Services.AddSingleton<IBrowserPolicyEnforcer, BrowserPolicyEnforcer>();

builder.Services.AddHostedService<Worker>();

var host = builder.Build();
host.Run();
