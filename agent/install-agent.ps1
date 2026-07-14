#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Installs the PioDeploy agent as a Windows service.
.DESCRIPTION
  Publishes are expected in the same folder as this script (PioDeployAgent.exe
  + appsettings.json). Pass the server URL and the project's API key; they are
  written into appsettings.json before the service is created.
.EXAMPLE
  .\install-agent.ps1 -ServerUrl "https://deploy.techpio.com" -ApiKey "pio_xxx"
#>
param(
    [Parameter(Mandatory = $true)] [string] $ServerUrl,
    [Parameter(Mandatory = $true)] [string] $ApiKey,
    [string] $InstallDir = "$env:ProgramFiles\PioDeploy\Agent"
)

$ErrorActionPreference = 'Stop'
$serviceName = 'PioDeployAgent'
$source = $PSScriptRoot

if (-not (Test-Path (Join-Path $source 'PioDeployAgent.exe'))) {
    throw "PioDeployAgent.exe not found next to this script. Publish the agent first."
}

# Stop + remove any previous install
$existing = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
if ($existing) {
    if ($existing.Status -eq 'Running') { Stop-Service $serviceName -Force }
    sc.exe delete $serviceName | Out-Null
    Start-Sleep -Seconds 2
}

New-Item -ItemType Directory -Force $InstallDir | Out-Null
Copy-Item -Path (Join-Path $source '*') -Destination $InstallDir -Recurse -Force -Exclude 'install-agent.ps1'

# Write configuration
$configPath = Join-Path $InstallDir 'appsettings.json'
$config = Get-Content $configPath -Raw | ConvertFrom-Json
$config.PioDeploy.ServerUrl = $ServerUrl
$config.PioDeploy.ApiKey = $ApiKey
$config | ConvertTo-Json -Depth 5 | Set-Content $configPath -Encoding UTF8

New-Service -Name $serviceName `
    -BinaryPathName (Join-Path $InstallDir 'PioDeployAgent.exe') `
    -DisplayName 'PioDeploy Agent' `
    -Description 'TechPio PioDeploy software deployment agent.' `
    -StartupType Automatic | Out-Null

sc.exe failure $serviceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null

Start-Service $serviceName
Write-Host "PioDeploy agent installed and started. Logs: $env:ProgramData\PioDeploy\logs"
