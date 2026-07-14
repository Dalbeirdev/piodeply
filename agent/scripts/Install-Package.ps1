<#
.SYNOPSIS
    PioDeploy lightweight installer engine.

.DESCRIPTION
    A dependency-free PowerShell port of the deployment pipeline: download +
    cache an installer, validate its SHA-256, verify its Authenticode
    signature, run it silently with a timeout, capture the exit code, log
    everything, and retry on failure with backoff.

    Supports EXE, MSI, MSIX, ZIP and portable apps. Idempotent: a cached,
    hash-matching download is reused, and installer-reported "already
    installed" / "reboot required" codes are treated as success.

.PARAMETER Url
    Download URL of the installer (or the .zip for zip/portable types).

.PARAMETER Type
    exe | msi | msix | zip | portable

.PARAMETER Sha256
    Expected SHA-256 of the downloaded file. Strongly recommended; when
    omitted the hash check is skipped and a warning is logged.

.PARAMETER SilentArgs
    Arguments for silent EXE installs (e.g. '/S' or '/qn'). Ignored for
    msi/msix/zip/portable.

.PARAMETER DestinationDir
    Extract/target directory for zip and portable installs.

.PARAMETER RequireSignature
    Fail the install unless the binary carries a valid Authenticode
    signature (exe/msi/msix only).

.EXAMPLE
    .\Install-Package.ps1 -Url https://.../7z.exe -Type exe -Sha256 ABC... -SilentArgs '/S'

.EXAMPLE
    .\Install-Package.ps1 -Url https://.../tool.zip -Type portable -DestinationDir 'C:\Tools\MyApp'
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)] [string] $Url,
    [Parameter(Mandatory = $true)] [ValidateSet('exe', 'msi', 'msix', 'zip', 'portable')] [string] $Type,
    [string] $Sha256,
    [string] $SilentArgs = '',
    [string] $DestinationDir,
    [string] $CacheDir = "$env:ProgramData\PioDeploy\cache",
    [string] $LogDir = "$env:ProgramData\PioDeploy\logs",
    [int]    $MaxRetries = 3,
    [int]    $RetryDelaySeconds = 30,
    [int]    $TimeoutSeconds = 1800,
    [switch] $RequireSignature
)

$ErrorActionPreference = 'Stop'

# MSI/EXE exit codes that are not failures.
$SuccessCodes = @(0, 1641, 3010)   # 0 = ok, 1641 = reboot initiated, 3010 = reboot required

# ─────────────────────────── Logging ────────────────────────────────────

New-Item -ItemType Directory -Force -Path $LogDir  | Out-Null
New-Item -ItemType Directory -Force -Path $CacheDir | Out-Null
$logFile = Join-Path $LogDir ("install-{0}.log" -f (Get-Date -Format 'yyyyMMdd'))

function Write-Log {
    param([string] $Message, [ValidateSet('INFO', 'WARN', 'ERROR')] [string] $Level = 'INFO')
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Message
    Add-Content -Path $logFile -Value $line
    switch ($Level) {
        'ERROR' { Write-Host $line -ForegroundColor Red }
        'WARN'  { Write-Host $line -ForegroundColor Yellow }
        default { Write-Host $line }
    }
}

# ─────────────────────────── Download + cache ───────────────────────────

function Get-CachedInstaller {
    param([string] $Url, [string] $Sha256)

    $extension = switch ($Type) { 'msi' { '.msi' } 'msix' { '.msix' } { $_ -in 'zip', 'portable' } { '.zip' } default { '.exe' } }
    # Cache filename is content-addressed when a hash is known, URL-addressed otherwise.
    $key = if ($Sha256) { $Sha256.ToLower() } else { [BitConverter]::ToString(
        [Security.Cryptography.SHA1]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($Url))).Replace('-', '').ToLower() }
    $cachePath = Join-Path $CacheDir ($key + $extension)

    if ((Test-Path $cachePath) -and (Test-Hash $cachePath $Sha256)) {
        Write-Log "Cache hit: $cachePath"
        return $cachePath
    }

    Write-Log "Downloading $Url"
    $tmp = "$cachePath.part"
    $sw = [Diagnostics.Stopwatch]::StartNew()
    try {
        # Invoke-WebRequest streams to disk and shows a progress bar natively.
        Invoke-WebRequest -Uri $Url -OutFile $tmp -UseBasicParsing -TimeoutSec $TimeoutSeconds
    }
    catch {
        if (Test-Path $tmp) { Remove-Item $tmp -Force }
        throw "Download failed: $($_.Exception.Message)"
    }
    $sw.Stop()
    $sizeMb = [math]::Round((Get-Item $tmp).Length / 1MB, 1)
    Write-Log ("Downloaded {0} MB in {1}s" -f $sizeMb, [math]::Round($sw.Elapsed.TotalSeconds, 1))

    if (-not (Test-Hash $tmp $Sha256)) {
        Remove-Item $tmp -Force
        throw 'SHA-256 mismatch: refusing to run a file that does not match the expected hash.'
    }

    Move-Item $tmp $cachePath -Force
    return $cachePath
}

function Test-Hash {
    param([string] $Path, [string] $Expected)
    if (-not $Expected) {
        Write-Log 'No SHA-256 supplied; skipping integrity check.' 'WARN'
        return $true
    }
    $actual = (Get-FileHash -Path $Path -Algorithm SHA256).Hash
    if ($actual -ieq $Expected) {
        Write-Log 'SHA-256 verified.'
        return $true
    }
    Write-Log "SHA-256 mismatch. expected=$Expected actual=$actual" 'ERROR'
    return $false
}

# ─────────────────────────── Signature ──────────────────────────────────

function Test-Signature {
    param([string] $Path)
    if (-not $RequireSignature -or $Type -notin @('exe', 'msi', 'msix')) { return }

    $sig = Get-AuthenticodeSignature -FilePath $Path
    if ($sig.Status -ne 'Valid') {
        throw "Authenticode signature is '$($sig.Status)' — refusing to run (RequireSignature)."
    }
    Write-Log "Signature valid: $($sig.SignerCertificate.Subject)"
}

# ─────────────────────────── Runners ────────────────────────────────────

function Invoke-Process {
    param([string] $FilePath, [string[]] $Arguments)

    Write-Log ("Running: {0} {1}" -f $FilePath, ($Arguments -join ' '))
    $proc = Start-Process -FilePath $FilePath -ArgumentList $Arguments -PassThru -Wait:$false -WindowStyle Hidden

    if (-not $proc.WaitForExit($TimeoutSeconds * 1000)) {
        try { $proc.Kill() } catch { }
        throw "Installer timed out after $TimeoutSeconds seconds."
    }
    return $proc.ExitCode
}

function Install-Once {
    param([string] $Installer)

    Test-Signature $Installer

    switch ($Type) {
        'exe' {
            $args = if ($SilentArgs) { $SilentArgs -split ' ' } else { @() }
            return Invoke-Process $Installer $args
        }
        'msi' {
            $log = Join-Path $LogDir ("msi-{0}.log" -f (Get-Date -Format 'yyyyMMddHHmmss'))
            return Invoke-Process 'msiexec.exe' @('/i', "`"$Installer`"", '/qn', '/norestart', '/l*v', "`"$log`"")
        }
        'msix' {
            Add-AppxPackage -Path $Installer -ForceApplicationShutdown
            return 0
        }
        { $_ -in 'zip', 'portable' } {
            if (-not $DestinationDir) { throw "DestinationDir is required for '$Type' installs." }
            New-Item -ItemType Directory -Force -Path $DestinationDir | Out-Null
            Write-Log "Extracting to $DestinationDir"
            Expand-Archive -Path $Installer -DestinationPath $DestinationDir -Force
            return 0
        }
    }
}

# ─────────────────────────── Orchestration ──────────────────────────────

function Test-ExitCode {
    param([int] $Code)
    # ZIP/MSIX return 0 from us; EXE/MSI codes are validated against the allow-list.
    if ($SuccessCodes -contains $Code) { return $true }
    return $false
}

Write-Log "=== Install start: $Url ($Type) ==="
$attempt = 0
$installed = $false

while (-not $installed -and $attempt -lt $MaxRetries) {
    $attempt++
    Write-Log "Attempt $attempt of $MaxRetries"
    try {
        $installer = Get-CachedInstaller -Url $Url -Sha256 $Sha256
        $code = Install-Once -Installer $installer

        if (Test-ExitCode $code) {
            $note = if ($code -eq 3010 -or $code -eq 1641) { ' (reboot required)' } else { '' }
            Write-Log "Success — exit code $code$note"
            $installed = $true
        }
        else {
            Write-Log "Installer returned failing exit code $code" 'ERROR'
        }
    }
    catch {
        Write-Log $_.Exception.Message 'ERROR'
    }

    if (-not $installed -and $attempt -lt $MaxRetries) {
        $wait = $RetryDelaySeconds * $attempt   # linear backoff
        Write-Log "Retrying in $wait seconds..." 'WARN'
        Start-Sleep -Seconds $wait
    }
}

if ($installed) {
    Write-Log '=== Install complete ==='
    exit 0
}

Write-Log "=== Install FAILED after $MaxRetries attempt(s) ===" 'ERROR'
exit 1
