@php /** One machine, by hand. A single self-contained line: no saved file, so
        the machine's execution policy (often "Restricted") never blocks it, and
        nothing can run out of order. PowerShell, not HTML: interpolate raw; the
        service escapes. */ @endphp
# PioDeploy agent — {!! $name !!} ({!! $company !!})
# Run in an ELEVATED PowerShell (Start menu -> right-click PowerShell ->
# "Run as administrator"), then paste the ONE line below and press Enter.
# It runs the installer in memory, so "running scripts is disabled" (execution
# policy) cannot stop it and there is no file to save.

[Net.ServicePointManager]::SecurityProtocol = 'Tls12'; & ([scriptblock]::Create((irm '{!! $scriptUrl !!}'))) -ApiKey '{!! $apiKey !!}'
