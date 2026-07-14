# PioDeploy Agent — silent & zero-touch deployment

The agent is a **.NET 8 Windows Service** that runs as **LocalSystem (SYSTEM)**.
Every deployment it performs — install, update, repair, uninstall, browser
policies — runs fully silently in session 0: no windows, no UAC prompts, no
installer dialogs. The only thing that has to happen once per machine is
installing the agent itself. This guide covers doing that with **zero user
interaction** across a fleet.

## What the installer does

`install-piodeploy-agent.ps1` (downloaded per-project from the portal) is
non-interactive and idempotent:

1. Downloads the agent bundle from the server
2. Removes any previous install
3. Extracts to `C:\Program Files\PioDeploy\Agent`
4. Writes `ServerUrl` + the project `ApiKey` into `appsettings.json`
5. Registers the `PioDeployAgent` service (auto-start, auto-restart on crash)
6. Starts it

It requires elevation (`#Requires -RunAsAdministrator`). Every push method
below runs as SYSTEM, which satisfies that automatically.

## The one bootstrap command

Everything reduces to this single line — fetch the per-project installer and
run it with the project's agent API key:

```powershell
powershell -ExecutionPolicy Bypass -Command "iwr '<DOWNLOAD_URL>' -OutFile $env:TEMP\pio.ps1 -UseBasicParsing; & $env:TEMP\pio.ps1 -ApiKey '<PROJECT_API_KEY>'"
```

- `<DOWNLOAD_URL>` — the project's agent download URL (Projects → the project →
  "Agent download URL"), e.g. `https://deploy.techpio.com/download/agent/<token>`.
  The token is the secret; the API key is **not** embedded in the URL.
- `<PROJECT_API_KEY>` — the project's agent key (shown once when the project was
  created / its key rotated). Rotate it any time from the projects list.

Put that line into whichever channel you already use:

## Method 1 — Group Policy (domain-joined)

1. Group Policy Management → new/edit GPO linked to the target OU.
2. **Computer Configuration → Policies → Windows Settings → Scripts → Startup**.
3. Add a PowerShell startup script with the bootstrap command above.
   Startup scripts run as **SYSTEM** at boot — silent, no user needed.
4. Machines enrol on next reboot. Re-running is safe (idempotent).

## Method 2 — Microsoft Intune (Entra-joined / cloud)

1. Intune → **Devices → Scripts and remediations → Platform scripts → Add
   (Windows 10 and later)**.
2. Upload a `.ps1` containing the bootstrap command.
3. **Run this script using the logged-on credentials: No** (→ runs as SYSTEM).
4. Assign to a device group. Intune installs it silently in the background.

## Method 3 — RMM (ConnectWise Automate, NinjaOne, Datto, etc.)

Use the RMM's **"Run Script / Run Command"** feature (they execute as SYSTEM):

- Paste the bootstrap command as a one-time PowerShell job, **or**
- Save it as a reusable script and target a device group / site.

This is the fastest route if you already run an RMM — one job enrols the fleet.

## Method 4 — Manual (single machine)

Elevated PowerShell (Run as administrator):

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
iwr '<DOWNLOAD_URL>' -OutFile install.ps1 -UseBasicParsing
.\install.ps1 -ApiKey '<PROJECT_API_KEY>'
```

## Verify enrolment

- Portal → **Computers**: the machine appears and flips to **online** within a
  minute (agents heartbeat every 60s).
- On the machine: `Get-Service PioDeployAgent` → `Running`.
- Logs: `C:\ProgramData\PioDeploy\logs`.

## Notes

- **API key handling.** GPO/Intune/RMM run in a trusted admin context, so the key
  in the job is acceptable — the same trust boundary as any deployment secret.
  Use a per-project key and rotate it if a machine is decommissioned.
- **Uninstall.** `sc.exe stop PioDeployAgent; sc.exe delete PioDeployAgent` then
  remove `C:\Program Files\PioDeploy\Agent`. (An RMM script can do this fleet-wide.)
- **Updates.** Re-running the bootstrap upgrades in place; it stops, replaces and
  restarts the service without user interaction.
