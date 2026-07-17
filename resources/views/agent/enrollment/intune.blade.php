<#
    PioDeploy agent — Intune / Entra platform script
    Project: {!! $name !!} ({!! $company !!})

    HOW TO DEPLOY IT
      1. Intune admin centre -> Devices -> Scripts and remediations
         -> Platform scripts -> Add -> Windows 10 and later.
      2. Upload this file.
      3. Settings:
           Run this script using the logged on credentials : No
           Enforce script signature check                  : No
           Run script in 64 bit PowerShell host            : Yes
      4. Assign to the device group holding the target machines.

    "No" on logged-on credentials is what makes it run as SYSTEM, which is
    the account the agent needs. Intune runs it once per device and retries
    on failure, so the version check below keeps a re-run harmless.

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
    $service = Get-Service -Name $serviceName -ErrorAction SilentlyContinue
    if ($null -ne $service) {
        $installed = Get-InstalledVersion
        if ($null -ne $installed -and $installed -ge [version]$minVersion) {
            Write-Log "Agent $installed already current on $env:COMPUTERNAME."
            exit 0   # Intune records success and stops retrying.
        }
        Write-Log "Agent $installed is older than $minVersion; upgrading."
    }

    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

    # Run the installer in memory, not from a saved .ps1 — a device on a
    # Restricted execution policy would refuse to run the file otherwise.
    $src = (Invoke-WebRequest -Uri $scriptUrl -UseBasicParsing).Content
    & ([scriptblock]::Create($src)) -ApiKey $apiKey

    Write-Log ("Agent {0} on {1}." -f (Get-InstalledVersion), $env:COMPUTERNAME)
    exit 0
}
catch {
    Write-Log "FAILED on $env:COMPUTERNAME : $($_.Exception.Message)"
    # Non-zero tells Intune it failed, so it will retry on the next cycle.
    exit 1
}
