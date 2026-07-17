@php /** Paste-into-an-RMM-job script. PowerShell, not HTML: interpolate raw
        and let the service make each value safe for its syntax. */ @endphp
# PioDeploy agent — {!! $name !!} ({!! $company !!})
# Paste into your RMM's "run script" job (NinjaOne, Datto, Atera, Action1,
# ConnectWise...) and target a device group. RMM agents already run as SYSTEM.
# Re-running is harmless: it exits when the agent is current.

$ErrorActionPreference = 'Stop'
$agentExe = Join-Path $env:ProgramFiles 'PioDeploy\Agent\PioDeployAgent.exe'

if (Test-Path $agentExe) {
    $installed = [version](Get-Item $agentExe).VersionInfo.FileVersion
    if ($installed -ge [version]'{!! $minVersion !!}') {
        Write-Output "PioDeploy agent $installed already current."
        exit 0
    }
    Write-Output "Upgrading PioDeploy agent $installed -> {!! $minVersion !!}."
}

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
# Run the installer in memory: no saved .ps1, so a Restricted execution policy
# on the endpoint cannot block it.
$src = (Invoke-WebRequest -Uri '{!! $scriptUrl !!}' -UseBasicParsing).Content
& ([scriptblock]::Create($src)) -ApiKey '{!! $apiKey !!}'

Write-Output "PioDeploy agent installed on $env:COMPUTERNAME."
