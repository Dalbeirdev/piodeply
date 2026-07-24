#requires -RunAsAdministrator
# One-time push of THIS machine onto agent 1.4.13 (the encoding fix: the
# swap helper is now written as UTF-8 WITH BOM and pure-ASCII content, so
# Windows PowerShell 5.1 can no longer misparse it into exit 1).
# A machine on an older agent GENERATES the broken helper itself, so it
# cannot self-update past it; this admin-run copy is the one manual hop
# needed. Every update after 1.4.13 is automatic.
# Preserves appsettings.json (the machine's real config) - only code changes.
$ErrorActionPreference = 'Stop'
$src = 'C:\xampp\htdocs\piodeploy-platform\agent\publish-1.4.13'
$dst = 'C:\Program Files\PioDeploy\Agent'

if (-not (Test-Path "$src\PioDeployAgent.dll")) { throw "Build not found at $src - run dotnet publish first." }
$new = (Get-Item "$src\PioDeployAgent.dll").VersionInfo.FileVersion
Write-Host "Installing agent $new over $dst (config preserved)..."

Stop-Service PioDeployAgent -Force
$deadline = (Get-Date).AddSeconds(20)
while ((Get-Process PioDeployAgent -ErrorAction SilentlyContinue) -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 500 }

# Copy everything EXCEPT appsettings*.json (keeps ApiKey / server URL intact).
Get-ChildItem $src -Recurse -File | Where-Object { $_.Name -notlike 'appsettings*.json' } | ForEach-Object {
    $rel = $_.FullName.Substring($src.Length).TrimStart('\')
    $target = Join-Path $dst $rel
    $dir = Split-Path $target -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Copy-Item $_.FullName $target -Force
}

Start-Service PioDeployAgent
Start-Sleep -Seconds 3
$now = (Get-Item "$dst\PioDeployAgent.dll").VersionInfo.FileVersion
Write-Host "Service status: $((Get-Service PioDeployAgent).Status)"
Write-Host "Installed version now: $now"
if ($now -eq $new) { Write-Host 'SUCCESS - on the fixed launcher. It will now self-update automatically.' -ForegroundColor Green }
else { Write-Warning "Version still $now - check C:\ProgramData\PioDeploy\logs." }
