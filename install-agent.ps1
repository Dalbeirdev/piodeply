#Requires -RunAsAdministrator
<#
  PioDeploy agent installer — IT Support Portal (VaultEdge IT Solutions)
  Generated 2026-07-14 by PioDeploy.

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
$serverUrl   = 'https://piodeploy.com'
$bundleUrl   = 'https://piodeploy.com/download/agent/cn0nxh5esyfd3biv03x7hsirbjdvp9yq/binary'
$serviceName = 'PioDeployAgent'

Write-Host "PioDeploy agent setup for project 'IT Support Portal'"
Write-Host "Server: $serverUrl"

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

Write-Host 'PioDeploy agent installed and started.'
Write-Host "Logs: $env:ProgramData\PioDeploy\logs"
