#Requires -RunAsAdministrator
<#
  PioDeploy agent installer — {{ $project->name }} ({{ $project->client->company_name }})
  Generated {{ now()->toDateString() }} by {{ config('app.name') }}.

  Usage (elevated PowerShell):
    .\install-piodeploy-agent.ps1 -ApiKey "pio_..."

  The API key is the project's agent key (shown once when the project was
  created or its key rotated). It is intentionally NOT embedded here.
#>
param(
    [Parameter(Mandatory = $true)] [string] $ApiKey,
    [string] $InstallDir = "$env:ProgramFiles\PioDeploy\Agent"
)

$ErrorActionPreference = 'Stop'
$serverUrl   = '{{ $serverUrl }}'
$bundleUrl   = '{{ $binaryUrl }}'
$serviceName = 'PioDeployAgent'

Write-Host "PioDeploy agent setup for project '{{ $project->name }}'"
Write-Host "Server: $serverUrl"

@if (! $hasBundle)
Write-Warning "The server has no published agent bundle yet."
Write-Warning "Ask your MSP to publish it (dotnet publish + upload), or install manually per the agent README."
exit 1
@else
# 1. Download the agent bundle
$tempZip = Join-Path $env:TEMP 'PioDeployAgent.zip'
Write-Host 'Downloading agent bundle...'
Invoke-WebRequest -Uri $bundleUrl -OutFile $tempZip -UseBasicParsing

# 2. Stop + remove any previous install
$existing = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existing) {
    if ($existing.Status -eq 'Running') { Stop-Service $serviceName -Force }
    sc.exe delete $serviceName | Out-Null
    Start-Sleep -Seconds 2
}

# 3. Extract
New-Item -ItemType Directory -Force $InstallDir | Out-Null
Expand-Archive -Path $tempZip -DestinationPath $InstallDir -Force
Remove-Item $tempZip -Force

# 4. Configure
$configPath = Join-Path $InstallDir 'appsettings.json'
$config = Get-Content $configPath -Raw | ConvertFrom-Json
$config.PioDeploy.ServerUrl = $serverUrl
$config.PioDeploy.ApiKey = $ApiKey
$config | ConvertTo-Json -Depth 5 | Set-Content $configPath -Encoding UTF8

# 5. Install + start the service
New-Service -Name $serviceName `
    -BinaryPathName (Join-Path $InstallDir 'PioDeployAgent.exe') `
    -DisplayName 'PioDeploy Agent' `
    -Description 'TechPio PioDeploy software deployment agent.' `
    -StartupType Automatic | Out-Null
sc.exe failure $serviceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null
Start-Service $serviceName

# 6. Ensure the Visual C++ runtime. Many app installers (Chrome among them)
#    fail to even launch without it — exit -1073741515 / 0xC0000135
#    (STATUS_DLL_NOT_FOUND), common on a fresh VM. The redist is idempotent:
#    it no-ops when an equal-or-newer version is already present. Best-effort,
#    so a hiccup here never fails the agent install.
try {
    Write-Host 'Ensuring Visual C++ runtime (needed by many installers)...'
    $vc = Join-Path $env:TEMP 'vc_redist.x64.exe'
    Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vc -UseBasicParsing
    $vcProc = Start-Process -FilePath $vc -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    Remove-Item $vc -Force -ErrorAction SilentlyContinue
    # 1638/3010 = already present / reboot-to-complete; both are success here.
    if ($vcProc.ExitCode -in 0, 1638, 3010) { Write-Host 'Visual C++ runtime present.' }
    else { Write-Warning "VC++ runtime installer returned $($vcProc.ExitCode)." }
} catch {
    Write-Warning "Could not ensure the Visual C++ runtime: $($_.Exception.Message)"
}

Write-Host 'PioDeploy agent installed and started.'
Write-Host "Logs: $env:ProgramData\PioDeploy\logs"
@endif
