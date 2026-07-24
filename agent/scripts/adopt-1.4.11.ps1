#requires -RunAsAdministrator
# One-time push of THIS machine onto agent 1.4.11 (the launcher fix).
# A machine on the old broken launcher cannot self-update, so this admin-run
# copy is the one manual hop needed; every update after 1.4.11 is automatic.
# Preserves appsettings.json (the machine's real config) — only code changes.
$ErrorActionPreference = 'Stop'
$src = 'C:\xampp\htdocs\piodeploy-platform\agent\publish-1.4.11'
$dst = 'C:\Program Files\PioDeploy\Agent'

if (-not (Test-Path "$src\PioDeployAgent.dll")) { throw "Build not found at $src — run dotnet publish first." }
$new = (Get-Item "$src\PioDeployAgent.dll").VersionInfo.FileVersion
Write-Host "Installing agent $new over $dst (config preserved)..."

Stop-Service PioDeployAgent -Force
# Let the process fully release its files.
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
if ($now -eq $new) { Write-Host 'SUCCESS — this machine is on the fixed launcher. It will now self-update automatically.' -ForegroundColor Green }
else { Write-Warning "Version still $now — check C:\ProgramData\PioDeploy\logs." }
