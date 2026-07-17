#Requires -RunAsAdministrator
<#
  PioDeploy agent installer — {{ $project->name }} ({{ $project->client->company_name }})
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
#    verify, and never let a repair hiccup abort the agent install — the
#    portal's readiness banner reports anything that could not be fixed.
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function Test-WingetWorks {
    # Resolve the real exe (the bare "winget" alias is not on SYSTEM's PATH),
    # then prove it actually launches — a fresh VM often has it present but
    # crashing on load with -1073741515 (missing VC++/UWP dependency).
    $exe = Get-ChildItem "$env:ProgramFiles\WindowsApps\Microsoft.DesktopAppInstaller_*_x64__8wekyb3d8bbwe\winget.exe" -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
    if (-not $exe) { $exe = (Get-Command winget.exe -ErrorAction SilentlyContinue | Select-Object -First 1).Source }
    if (-not $exe) { return $false }
    try { & $exe --version *> $null; return ($LASTEXITCODE -eq 0) } catch { return $false }
}

# 5a. Visual C++ desktop runtime. Many app installers (Chrome) — and winget
#     itself — fail to launch without it: exit -1073741515 / 0xC0000135
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
sc.exe failure $serviceName reset= 86400 actions= restart/60000/restart/60000/restart/60000 | Out-Null
Start-Service $serviceName

Write-Host 'PioDeploy agent installed and started.'
Write-Host "Logs: $env:ProgramData\PioDeploy\logs"
@endif
