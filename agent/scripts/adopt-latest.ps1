#requires -RunAsAdministrator
# Recovery + one-time hop onto agent 1.4.15 (the detached-pipe fix: the
# update helper no longer holds std pipes to the dying service, so it can
# no longer be killed mid-swap by a broken-pipe write).
# Also repairs a machine left dark by the 1.4.13 hang: stray helper killed,
# watchdog re-enabled, stale staging removed, service started.
# Preserves appsettings.json (the machine's real config) - only code changes.
# KEEP THIS FILE PURE ASCII - PowerShell 5.1 misparses fancy punctuation.
$ErrorActionPreference = 'Stop'
$src = 'C:\xampp\htdocs\piodeploy-platform\agent\publish-1.4.15'
$dst = 'C:\Program Files\PioDeploy\Agent'

if (-not (Test-Path "$src\PioDeployAgent.dll")) { throw "Build not found at $src - run dotnet publish first." }
$new = (Get-Item "$src\PioDeployAgent.dll").VersionInfo.FileVersion
Write-Host "Installing agent $new over $dst (config preserved)..."

# 1. Kill any hung update helper from the failed swap.
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -like '*apply-update.ps1*' } |
    ForEach-Object { Write-Host "Killing stray helper pid $($_.ProcessId)"; Stop-Process -Id $_.ProcessId -Force }

# 2. Stop the service if it is running (it is likely already stopped).
if ((Get-Service PioDeployAgent).Status -ne 'Stopped') { Stop-Service PioDeployAgent -Force }
$deadline = (Get-Date).AddSeconds(20)
while ((Get-Process PioDeployAgent -ErrorAction SilentlyContinue) -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 500 }

# 3. Copy everything EXCEPT appsettings*.json (keeps ApiKey / server URL intact).
Get-ChildItem $src -Recurse -File | Where-Object { $_.Name -notlike 'appsettings*.json' } | ForEach-Object {
    $rel = $_.FullName.Substring($src.Length).TrimStart('\')
    $target = Join-Path $dst $rel
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Copy-Item $_.FullName $target -Force
}

# 4. Clear the stale staged bundle so the new agent starts from a clean slate.
Remove-Item 'C:\ProgramData\PioDeploy\update\staging' -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item 'C:\ProgramData\PioDeploy\update\PioDeployAgent.zip' -Force -ErrorAction SilentlyContinue

# 5. Re-enable the watchdog the failed helper left disabled.
schtasks.exe /Change /TN PioDeployAgentWatchdog /ENABLE 2>$null | Out-Null
Write-Host "Watchdog re-enabled."

# 6. Start the service and verify.
Start-Service PioDeployAgent
Start-Sleep -Seconds 3
$now = (Get-Item "$dst\PioDeployAgent.dll").VersionInfo.FileVersion
Write-Host "Service status: $((Get-Service PioDeployAgent).Status)"
Write-Host "Installed version now: $now"
if ($now -eq $new) { Write-Host 'SUCCESS - recovered and on the fixed agent. It will now self-update automatically.' -ForegroundColor Green }
else { Write-Warning "Version still $now - check C:\ProgramData\PioDeploy\logs." }
