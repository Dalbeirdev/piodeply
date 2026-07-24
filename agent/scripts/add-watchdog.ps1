#requires -RunAsAdministrator
# Adds the PioDeploy watchdog to a machine that is missing it (for example
# one that was revived by hand-copy instead of the installer).
# SYSTEM scheduled task, every 2 minutes: if the agent service is not
# Running, start it. Identical to what the installer creates.
# KEEP THIS FILE PURE ASCII - PowerShell 5.1 misparses fancy punctuation.
$ErrorActionPreference = 'Stop'
$serviceName  = 'PioDeployAgent'
$watchdogName = 'PioDeployAgentWatchdog'
$watchdogCmd  = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command " +
    "`"if ((Get-Service '$serviceName' -ErrorAction SilentlyContinue).Status -ne 'Running') { Start-Service '$serviceName' -ErrorAction SilentlyContinue }`""

cmd.exe /c "schtasks /Delete /TN $watchdogName /F >nul 2>&1"
schtasks.exe /Create /TN $watchdogName /TR $watchdogCmd /SC MINUTE /MO 2 /RU SYSTEM /RL HIGHEST /F | Out-Null

# Belt and suspenders: make sure crash recovery + non-crash restart are set.
sc.exe failure $serviceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null
sc.exe failureflag $serviceName 1 | Out-Null

Write-Host "Watchdog task created (runs every 2 minutes as SYSTEM)."
cmd.exe /c "schtasks /Query /TN $watchdogName /FO LIST | findstr /C:TaskName /C:Status"
Write-Host 'Test it: Stop-Service PioDeployAgent, wait 2 minutes, it comes back by itself.'
