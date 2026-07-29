#Requires -RunAsAdministrator
<#
  PioDeploy agent installer - {{ $project->name }} ({{ $project->client->company_name }})
  Generated {{ now()->toDateString() }} by {{ config('app.name') }}.

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
$serverUrl   = '{{ $serverUrl }}'
$bundleUrl   = '{{ $binaryUrl }}'
$serviceName = 'PioDeployAgent'

Write-Host "PioDeploy agent setup for project '{{ $project->name }}'"
Write-Host "Server: $serverUrl"

@if (! $hasBundle)
Write-Warning "The server has no published agent bundle yet."
Write-Warning "Ask your MSP to publish it (dotnet publish + upload), or install manually per the agent README."
exit 1
@else
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

# 5. Preflight the machine so the agent can actually deploy software.
#    The agent runs as SYSTEM; two things have to work from that account or
#    every install silently fails. We check each, repair only what is broken,
#    verify, and never let a repair hiccup abort the agent install - the
#    portal's readiness banner reports anything that could not be fixed.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Test-WingetWorks {
    # Resolve the real exe (the bare "winget" alias is not on SYSTEM's PATH),
    # then prove it actually launches - a fresh VM often has it present but
    # crashing on load with -1073741515 (missing VC++/UWP dependency).
    $exe = Get-ChildItem "$env:ProgramFiles\WindowsApps\Microsoft.DesktopAppInstaller_*_x64__8wekyb3d8bbwe\winget.exe" -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
    if (-not $exe) { $exe = (Get-Command winget.exe -ErrorAction SilentlyContinue | Select-Object -First 1).Source }
    if (-not $exe) { return $false }
    try { & $exe --version *> $null; return ($LASTEXITCODE -eq 0) } catch { return $false }
}

# 5a. Visual C++ desktop runtime. Many app installers (Chrome) - and winget
#     itself - fail to launch without it: exit -1073741515 / 0xC0000135
#     (STATUS_DLL_NOT_FOUND). The redist is idempotent (no-ops when current).
try {
    Write-Host 'Ensuring Visual C++ runtime...'
    $vc = Join-Path $env:TEMP 'vc_redist.x64.exe'
    Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vc -UseBasicParsing
    $vcProc = Start-Process -FilePath $vc -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    Remove-Item $vc -Force -ErrorAction SilentlyContinue
    if ($vcProc.ExitCode -in 0, 1638, 3010) { Write-Host 'Visual C++ runtime present.' }
    else { Write-Warning "VC++ runtime installer returned $($vcProc.ExitCode)." }
} catch {
    Write-Warning "Could not ensure the Visual C++ runtime: $($_.Exception.Message)"
}

# 5b. winget for the SYSTEM account. Only repair if it is actually broken.
Write-Host 'Checking winget (Windows Package Manager)...'
if (Test-WingetWorks) {
    Write-Host 'winget is working.'
} else {
    Write-Host 'winget is missing or broken for this account; repairing for all users...'

    # Primary: Microsoft's supported repair, which pulls winget plus its
    # VCLibs / UI.Xaml dependencies and provisions them for every user (the
    # -AllUsers is what exposes it to SYSTEM).
    try {
        Install-PackageProvider -Name NuGet -Force -ErrorAction Stop | Out-Null
        Set-PSRepository -Name PSGallery -InstallationPolicy Trusted -ErrorAction SilentlyContinue
        Install-Module -Name Microsoft.WinGet.Client -Force -Scope AllUsers -ErrorAction Stop
        Import-Module Microsoft.WinGet.Client -ErrorAction Stop
        Repair-WinGetPackageManager -AllUsers -Latest -ErrorAction Stop
    } catch {
        Write-Warning "winget module repair did not complete: $($_.Exception.Message)"
    }

    # Fallback: provision winget and its dependencies straight from Microsoft.
    if (-not (Test-WingetWorks)) {
        try {
            $wtmp = Join-Path $env:TEMP 'pd-winget'
            New-Item -ItemType Directory -Force $wtmp | Out-Null
            # Parallel lists (no @() literal, which Blade could misread).
            $depUrls  = 'https://aka.ms/Microsoft.VCLibs.x64.14.00.Desktop.appx',
                        'https://github.com/microsoft/microsoft-ui-xaml/releases/download/v2.8.6/Microsoft.UI.Xaml.2.8.x64.appx',
                        'https://aka.ms/getwinget'
            $depFiles = 'vclibs.appx', 'uixaml.appx', 'winget.msixbundle'
            for ($k = 0; $k -lt $depUrls.Count; $k++) {
                $p = Join-Path $wtmp $depFiles[$k]
                Invoke-WebRequest -Uri $depUrls[$k] -OutFile $p -UseBasicParsing
                try { Add-AppxProvisionedPackage -Online -PackagePath $p -SkipLicense -ErrorAction Stop | Out-Null }
                catch { Write-Warning "Could not provision $($depFiles[$k]): $($_.Exception.Message)" }
            }
            Remove-Item $wtmp -Recurse -Force -ErrorAction SilentlyContinue
        } catch {
            Write-Warning "winget fallback provisioning failed: $($_.Exception.Message)"
        }
    }

    if (Test-WingetWorks) { Write-Host 'winget repaired.' }
    else { Write-Warning 'winget could not be made ready; the agent will still run and the portal will flag this machine.' }
}

# 6. Install + start the service
New-Service -Name $serviceName `
    -BinaryPathName (Join-Path $InstallDir 'PioDeployAgent.exe') `
    -DisplayName 'PioDeploy Agent' `
    -Description 'TechPio PioDeploy software deployment agent.' `
    -StartupType Automatic | Out-Null

# Windows restarts the service by itself if it CRASHES. failureflag=1 makes
# that apply to any non-zero exit too, not just a hard crash.
sc.exe failure $serviceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null
sc.exe failureflag $serviceName 1 | Out-Null

# 7. Watchdog - the piece that keeps the agent alive no matter what.
#    Recovery actions only cover an unexpected exit; they do NOT restart a
#    service someone STOPS by hand, or one left stopped by a bad update. A
#    SYSTEM scheduled task every 2 minutes closes that gap: if the service is
#    not running, it starts it. Stopping the agent therefore does nothing
#    lasting - it is back within two minutes - without blocking the agent's
#    own controlled stops for self-update (the update helper restarts it far
#    faster than the watchdog interval). This is exactly what would have kept
#    a fleet of older agents from going dark.
#    "net start" on an already-running service is a harmless no-op error, so
#    the task needs NO embedded quotes - schtasks /TR quoting silently broke
#    the quoted-powershell variant (task never registered).
#
#    Battery: schtasks-created tasks default to "start only on AC power" and
#    "stop when switching to battery" - on a laptop running on battery the
#    watchdog would simply NEVER fire (found the hard way: a keeper task
#    silently skipped every slot until the charger was plugged in).
#    Set-PioTaskBatteryProof strips those conditions from every task we make.
function Set-PioTaskBatteryProof([string]$name) {
    try {
        $s = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
             -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero)
        Set-ScheduledTask -TaskName $name -Settings $s -ErrorAction Stop | Out-Null
    } catch { Write-Warning "Could not set power conditions on task $name : $($_.Exception.Message)" }
}
$watchdogName = 'PioDeployAgentWatchdog'
cmd.exe /c "schtasks /Delete /TN $watchdogName /F >nul 2>&1"
schtasks.exe /Create /TN $watchdogName /TR "net start $serviceName" /SC MINUTE /MO 2 /RU SYSTEM /RL HIGHEST /F | Out-Null
if ($LASTEXITCODE -ne 0) { Write-Warning "Watchdog task could not be created (schtasks exit $LASTEXITCODE)." }
Set-PioTaskBatteryProof $watchdogName

# 8. Tray status indicator (per-user). The service runs as SYSTEM in
#    session 0 and cannot draw UI, so a tiny PowerShell helper runs in each
#    logged-in user's session, reads the status file the service writes, and
#    shows a system-tray icon with agent health. Read-only - it changes
#    nothing, it just lets the user see the agent is present and healthy.
$trayDir = Join-Path $env:ProgramData 'PioDeploy'
New-Item -ItemType Directory -Force $trayDir | Out-Null
$trayScript = Join-Path $trayDir 'pio-tray.ps1'
@'
# PioDeploy tray helper - status only. Reads C:\ProgramData\PioDeploy\status.json.
# Crash log: %ProgramData%\PioDeploy\logs\tray.log (why-did-it-die evidence).
$trayLog = Join-Path $env:ProgramData 'PioDeploy\logs\tray.log'
# Single instance per session: the keeper task fires every few minutes and
# must be a silent no-op when the tray is already up (else: duplicate icons).
$script:pioMutex = New-Object System.Threading.Mutex($false, 'Local\PioDeployTray')
if (-not $script:pioMutex.WaitOne(0)) { exit }
try {
"$(Get-Date -Format s)  tray starting (pid $PID)" | Out-File $trayLog -Append -Encoding utf8
# At logon the taskbar may not exist yet; a NotifyIcon created too early
# never shows. Give explorer a moment before creating the icon.
Start-Sleep -Seconds 8
Add-Type -AssemblyName System.Windows.Forms, System.Drawing
$ErrorActionPreference = 'SilentlyContinue'
$statusPath = Join-Path $env:ProgramData 'PioDeploy\status.json'

$ni = New-Object System.Windows.Forms.NotifyIcon
# Brand icon: pio.ico ships in the agent bundle; fall back to the exe's
# embedded icon, then to a stock shield, so the tray never fails to show.
$ni.Icon = [System.Drawing.SystemIcons]::Shield
foreach ($cand in @(
    (Join-Path $env:ProgramFiles 'PioDeploy\Agent\pio.ico'),
    (Join-Path $env:ProgramData  'PioDeploy\pio.ico'))) {
    if (Test-Path $cand) { try { $ni.Icon = New-Object System.Drawing.Icon($cand, 16, 16); break } catch {} }
}
if ($ni.Icon -eq [System.Drawing.SystemIcons]::Shield) {
    $exe = Join-Path $env:ProgramFiles 'PioDeploy\Agent\PioDeployAgent.exe'
    if (Test-Path $exe) { try { $ni.Icon = [System.Drawing.Icon]::ExtractAssociatedIcon($exe) } catch {} }
}
$ni.Text = 'PioDeploy Agent'
$ni.Visible = $true
$menu = New-Object System.Windows.Forms.ContextMenuStrip
$miStatus  = $menu.Items.Add('Checking...'); $miStatus.Enabled = $false
$miVersion = $menu.Items.Add('');            $miVersion.Enabled = $false
$miSeen    = $menu.Items.Add('');            $miSeen.Enabled = $false
$miPending = $menu.Items.Add('');            $miPending.Enabled = $false
$menu.Items.Add('-') | Out-Null
$miLogs = $menu.Items.Add('Open logs folder')
$miLogs.add_Click({ Start-Process (Join-Path $env:ProgramData 'PioDeploy\logs') })
$ni.ContextMenuStrip = $menu

$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 30000
$refresh = {
    try {
        if (Test-Path $statusPath) {
            $s = Get-Content $statusPath -Raw | ConvertFrom-Json
            $seen = [datetime]::Parse($s.checked_in_utc).ToUniversalTime()
            $mins = [math]::Round(([datetime]::UtcNow - $seen).TotalMinutes)
            $online = $mins -lt 5
            $miStatus.Text  = if ($online) { 'Status: Online' } else { 'Status: Offline' }
            $miVersion.Text = "Version: $($s.version)" + $(if ($s.latest -and $s.latest -ne $s.version) { " (updating to $($s.latest))" } else { '' })
            $miSeen.Text    = "Last check-in: $mins min ago"
            $miPending.Text = "Pending updates: $($s.pending_jobs)"
            $ni.Text = "PioDeploy Agent - " + $(if ($online) { "online, v$($s.version)" } else { 'offline' })
        } else {
            $miStatus.Text = 'Status: starting...'; $ni.Text = 'PioDeploy Agent'
        }
    } catch { $miStatus.Text = 'Status: unknown' }
}
& $refresh
$timer.add_Tick($refresh)
$timer.Start()
$ctx = New-Object System.Windows.Forms.ApplicationContext
[System.Windows.Forms.Application]::Run($ctx)
"$(Get-Date -Format s)  tray exited normally" | Out-File $trayLog -Append -Encoding utf8
} catch {
"$(Get-Date -Format s)  tray CRASHED: $($_.Exception.Message)" | Out-File $trayLog -Append -Encoding utf8
}
'@ | Set-Content $trayScript -Encoding UTF8

# Launcher: the scheduled tasks run THIS one-second script, which spawns the
# tray detached and exits. A forever-running tray must never BE the task -
# Task Scheduler stops long-running instances at its own lifecycle points
# (time limits, next-trigger policies), which kept killing the icon.
$trayLauncher = Join-Path $trayDir 'pio-tray-launch.ps1'
@'
Start-Process powershell.exe -WindowStyle Hidden -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File','C:\ProgramData\PioDeploy\pio-tray.ps1'
'@ | Set-Content $trayLauncher -Encoding UTF8

# NO quotes inside /TR (the path has no spaces): schtasks mangles embedded
# quotes and silently refuses the task - the same bug that once left the
# watchdog unregistered. Exit codes are checked, not swallowed.
$trayCmd  = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File $trayLauncher"
$trayTask = 'PioDeployAgentTray'
cmd.exe /c "schtasks /Delete /TN $trayTask /F >nul 2>&1"
# /RU Users + ONLOGON: runs in whichever user logs on, in their session.
schtasks.exe /Create /TN $trayTask /TR $trayCmd /SC ONLOGON /RU Users /RL LIMITED /F | Out-Null
if ($LASTEXITCODE -ne 0) { Write-Warning "Tray logon task not created (schtasks exit $LASTEXITCODE)." }
Set-PioTaskBatteryProof $trayTask
# Keeper: every 5 minutes, relaunch the tray if someone closed it. The
# mutex inside pio-tray.ps1 makes this a no-op while the tray is running.
$keeperTask = 'PioDeployAgentTrayKeeper'
cmd.exe /c "schtasks /Delete /TN $keeperTask /F >nul 2>&1"
schtasks.exe /Create /TN $keeperTask /TR $trayCmd /SC MINUTE /MO 5 /RU Users /RL LIMITED /F | Out-Null
if ($LASTEXITCODE -ne 0) { Write-Warning "Tray keeper task not created (schtasks exit $LASTEXITCODE)." }
Set-PioTaskBatteryProof $keeperTask
# Start it now for the user running the installer, without waiting for a re-login.
cmd.exe /c "schtasks /Run /TN $trayTask >nul 2>&1"

Start-Service $serviceName

Write-Host 'PioDeploy agent installed and started (with self-healing watchdog).'
Write-Host "Logs: $env:ProgramData\PioDeploy\logs"
@endif
