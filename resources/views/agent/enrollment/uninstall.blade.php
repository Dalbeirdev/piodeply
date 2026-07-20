@php /** Removes the agent from a machine by hand — the escape hatch for a
        machine whose agent is broken, offline, or being decommissioned, where
        the portal's "Uninstall agent" button (which asks the agent to remove
        itself at check-in) can never reach it. Needs no API key: it only
        touches the local machine. PowerShell, not HTML: interpolate raw. */ @endphp
# PioDeploy agent removal — {!! $name !!} ({!! $company !!})
# Run in an ELEVATED PowerShell. Removes the agent service and all its files.
# Software that was installed through PioDeploy is NOT removed.
# The computer's record and history remain in the portal until deleted there.

$ErrorActionPreference = 'SilentlyContinue'
Stop-Service PioDeployAgent -Force
sc.exe delete PioDeployAgent | Out-Null
schtasks.exe /Delete /TN PioDeployAgentSelfUpdate /F 2>$null
schtasks.exe /Delete /TN PioDeployAgentUninstall /F 2>$null
Remove-Item 'C:\Program Files\PioDeploy' -Recurse -Force
Remove-Item 'C:\ProgramData\PioDeploy' -Recurse -Force
Write-Host 'PioDeploy agent removed.'
