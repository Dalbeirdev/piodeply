@php /** GPO computer-startup script. Runs as SYSTEM at boot, every boot.
        PowerShell, not HTML: interpolate raw and let the service make each
        value safe for the syntax it lands in. */ @endphp
<#
    PioDeploy agent — Group Policy computer startup script
    Project: {!! $name !!} ({!! $company !!})

    WHAT THIS DOES
      Installs the PioDeploy agent on every computer the GPO is linked to,
      with nobody logged in and nothing to click. Startup scripts run as
      SYSTEM at boot, which is exactly the account the agent needs.

    HOW TO DEPLOY IT
      1. Save this file to a share every domain computer can read, e.g.
         \\yourdomain.local\NETLOGON\PioDeploy\Install-PioDeployAgent.ps1
         (NETLOGON is readable by Domain Computers by default.)
      2. Open Group Policy Management (gpmc.msc).
      3. Right-click the OU holding the target computers -> "Create a GPO in
         this domain, and Link it here..." -> name it "Deploy PioDeploy Agent".
      4. Right-click the new GPO -> Edit -> Computer Configuration -> Policies
         -> Windows Settings -> Scripts (Startup/Shutdown) -> Startup
         -> PowerShell Scripts tab -> Add -> browse to the UNC path above.
      5. On the target machines: gpupdate /force, then reboot. Startup scripts
         only run at boot.

    SAFE TO RUN EVERY BOOT
      It exits in milliseconds when the agent is already installed and at or
      above {!! $minVersion !!}. It only acts when there is something to do —
      a fresh install, or upgrading an agent left behind on an older build.

    Log: C:\Windows\Temp\PioDeploy-Enrollment.log
#>

$ErrorActionPreference = 'Stop'

$serviceName = 'PioDeployAgent'
$scriptUrl   = '{!! $scriptUrl !!}'
$apiKey      = '{!! $apiKey !!}'
$minVersion  = '{!! $minVersion !!}'
$agentExe    = Join-Path $env:ProgramFiles 'PioDeploy\Agent\PioDeployAgent.exe'
$logPath     = Join-Path $env:windir 'Temp\PioDeploy-Enrollment.log'

function Write-Log([string] $message) {
    "{0}  {1}" -f (Get-Date -Format 's'), $message |
        Out-File -FilePath $logPath -Append -Encoding utf8
}

function Get-InstalledVersion {
    if (-not (Test-Path $agentExe)) { return $null }
    try { return [version](Get-Item $agentExe).VersionInfo.FileVersion }
    catch { return $null }
}

try {
    # Already here and current? Then this boot costs nothing.
    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if ($null -ne $service) {
        $installed = Get-InstalledVersion
        if ($null -ne $installed -and $installed -ge [version]$minVersion) {
            if ($service.Status -ne 'Running') {
                Start-Service $serviceName
                Write-Log "Agent $installed present but stopped; started it."
            }
            exit 0
        }
        Write-Log "Agent $installed is older than $minVersion; upgrading."
    }

    # Some Server builds still default to TLS 1.0, which the site rejects.
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

    # Run the installer in memory, not from a saved .ps1 — a domain machine on
    # a Restricted execution policy would refuse to run the file otherwise.
    $src = (Invoke-WebRequest -Uri $scriptUrl -UseBasicParsing).Content
    & ([scriptblock]::Create($src)) -ApiKey $apiKey

    Write-Log ("Agent {0} on {1}." -f (Get-InstalledVersion), $env:COMPUTERNAME)
    exit 0
}
catch {
    # Never fail the boot: log it and let the next restart try again.
    Write-Log "FAILED on $env:COMPUTERNAME : $($_.Exception.Message)"
    exit 1
}
