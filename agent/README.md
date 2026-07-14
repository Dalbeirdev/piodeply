# PioDeploy Windows Agent

.NET 8 Worker Service that enrolls a Windows machine into a PioDeploy project,
heartbeats every 60 seconds, and reports system inventory (WMI + registry).

## How it works

- **Identity**: a UUID generated once and persisted at
  `%ProgramData%\PioDeploy\agent-id` — reinstalling keeps the same server-side
  computer record (registration is idempotent by UUID).
- **Auth**: the project's API key (`pio_…`) is sent as `X-Api-Key` on every
  request. The server stores only a hash; rotating the key in the portal cuts
  the fleet over immediately.
- **Loop**: register (with retry/backoff) → heartbeat every 60s (server can
  retune the interval) → full inventory refresh hourly. A 404 on heartbeat
  triggers automatic re-registration; network failures back off exponentially
  to a 15-minute cap and never crash the service.
- **Inventory**: hostname, serial, make/model, OS + build, CPU, RAM, disk,
  private IP + MAC, Secure Boot (registry), TPM (WMI — needs elevation; the
  agent degrades gracefully without it). The server records the public IP from
  the connection itself; agents never self-report it.
- **Logs**: rolling daily files at `%ProgramData%\PioDeploy\logs\` (size-capped),
  no external logging dependencies.
- **Jobs**: when the heartbeat reports pending work, the agent claims jobs
  (priority-ordered) and executes them sequentially via the installer engine,
  reporting exit code + output log per job.

## Installer engine

Strategy per installer type, orchestrated by `InstallerEngine` (never throws;
every outcome becomes a reportable result):

| Type | Execution | Notes |
|---|---|---|
| winget | `winget install/upgrade/uninstall --exact --silent` | `install` uses `--no-upgrade` (ensure-present; `update` upgrades). Already-installed / no-applicable-upgrade exit codes are success. `rollback` pins `--version --force`. |
| choco | `choco install/upgrade/uninstall -y` | 1641/3010 (reboot) are success. |
| msi | download → **SHA-256 verify** → `msiexec /i|/x file /qn /norestart` + args | 3010/1641 success. |
| exe | download → verify → run with silent args | uninstall unsupported (use winget/choco/msi). |
| zip / portable | download → verify → extract to `%ProgramData%\PioDeploy\Apps\<name>` | partial extraction rolled back. |
| msix | download → verify → `Add-AppxPackage` | |
| powershell | download → verify → `powershell -ExecutionPolicy Bypass -File` | |

Binary payloads are **never executed without a checksum match**; downloads go
to a per-job scratch dir removed afterwards. Processes run with argument
lists (no shell), merged-output capture capped at 60 KB, 30-minute timeout
with process-tree kill.

## Build & test

```powershell
cd agent
dotnet build
dotnet test
```

## Publish + install on a machine

```powershell
dotnet publish src\PioDeploy.Agent -c Release -o publish
Copy-Item install-agent.ps1 publish\
# on the target machine, elevated:
.\publish\install-agent.ps1 -ServerUrl "https://deploy.techpio.com" -ApiKey "pio_xxx"
```

Run interactively for debugging (console mode, Ctrl+C to stop):

```powershell
dotnet run --project src\PioDeploy.Agent -- --PioDeploy:ServerUrl=http://localhost:8766 --PioDeploy:ApiKey=pio_xxx
```
