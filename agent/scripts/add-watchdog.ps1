#requires -RunAsAdministrator
# Adds the PioDeploy watchdog to a machine that is missing it (for example
# one that was revived by hand-copy instead of the installer).
# SYSTEM scheduled task, every 2 minutes: net start PioDeployAgent.
# "net start" on an already-running service is a harmless no-op error, so
# the task needs NO embedded quotes - schtasks /TR quoting has broken every
# fancier variant we tried.
# KEEP THIS FILE PURE ASCII - PowerShell 5.1 misparses fancy punctuation.
$ErrorActionPreference = 'Stop'
$serviceName  = 'PioDeployAgent'
$watchdogName = 'PioDeployAgentWatchdog'

cmd.exe /c "schtasks /Delete /TN $watchdogName /F >nul 2>&1"
schtasks.exe /Create /TN $watchdogName /TR "net start $serviceName" /SC MINUTE /MO 2 /RU SYSTEM /RL HIGHEST /F
if ($LASTEXITCODE -ne 0) { throw "schtasks /Create failed with exit code $LASTEXITCODE" }

# Battery: schtasks defaults to "start only on AC power" - on a laptop the
# watchdog would never fire on battery. Strip the power conditions.
$s = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
     -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero)
Set-ScheduledTask -TaskName $watchdogName -Settings $s | Out-Null
Write-Host "Power conditions stripped: watchdog runs on battery too."

# Belt and suspenders: make sure crash recovery + non-crash restart are set.
sc.exe failure $serviceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null
sc.exe failureflag $serviceName 1 | Out-Null

Write-Host "Watchdog task created (runs every 2 minutes as SYSTEM)."
cmd.exe /c "schtasks /Query /TN $watchdogName /FO LIST | findstr /C:TaskName /C:Status"
Write-Host 'Test it: Stop-Service PioDeployAgent, wait 2 minutes, it comes back by itself.'
