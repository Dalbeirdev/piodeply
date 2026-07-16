@php /** One machine, by hand. Deliberately short enough to read before running.
        PowerShell, not HTML: interpolate raw; the service escapes. */ @endphp
# PioDeploy agent — {!! $name !!} ({!! $company !!})
# Run in an ELEVATED PowerShell (Start menu -> right-click PowerShell ->
# "Run as administrator") on the machine you want to enrol.

irm '{!! $scriptUrl !!}' -OutFile "$env:TEMP\install-piodeploy-agent.ps1"
& "$env:TEMP\install-piodeploy-agent.ps1" -ApiKey '{!! $apiKey !!}'
