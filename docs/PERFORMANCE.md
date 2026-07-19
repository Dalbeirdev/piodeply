# Performance & fleet-scale benchmarks

Measured 2026-07-20 on MariaDB 10.4 (XAMPP, single node) with the repeatable
harness:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS piodeploy_bench;"
DB_DATABASE=piodeploy_bench php artisan perf:bench 1000
```

The command **refuses to run** unless the database name contains `bench`.
It seeds a synthetic fleet (N devices, 30 software rows each, 10 winget
packages, 10 policies, job history, scoped browser policies) and times the
hot paths with wall time + query counts.

## The finding: policy enforcement scaled with devices × policies

`policies:enforce` (the 5-minute scheduler pass) asked the database 15–20
questions **per machine per policy** — installed state, in-flight jobs,
recent successes, failure backoff:

| Devices | Before (queries / wall) | After (steady state) |
|---|---|---|
| 100  | 2,135 / 1.6 s  | **53 / 0.08 s** |
| 500  | 10,655 / 5.9 s | **303 / 0.9 s** |
| 1000 | 21,305 / 10.5 s | **603 / 1.7 s** |

Fix: `PolicyBatchContext` — three set-based queries per policy (software
rows for the manager id, scan-liveness per machine, full job history for the
package) answered in memory. The per-computer path (`enforceForComputer`,
used on each agent report) is unchanged and still answers from live queries;
both paths run the same enforcement test suite, so they cannot drift apart.

A first pass that genuinely rolls out (queueing ~2,000 jobs at 1000 devices)
costs ~6 s — that is real work, done once per rollout.

## Steady-state numbers at 1000 devices (after)

| Path | Wall | Queries |
|---|---|---|
| policies:enforce (steady state, every 5 min) | 1.7 s | 603 |
| Agent software report (per machine, incl. enforcement) | ~7–12 ms | 14 |
| Browser-policy document per machine | ~2 ms | 3 |
| Dashboard browser-compliance widget | ~57 ms | 7 |
| Computers list first page | ~4 ms | 3 |
| Dashboard stat counts | ~13 ms | 8 |

**Capacity estimate (single modest node):** an agent reporting every 5 min
at 1000 devices ≈ 3.3 reports/sec at ~10 ms each ≈ 3% of one core.
Enforcement adds 1.7 s per 5-min window (~0.6%). Headroom to several
thousand devices before the next bottleneck (dashboard aggregates).

## What was checked and judged healthy

- **Indexes** — the hot paths are covered: `deployment_jobs(computer_id,
  status, priority)` + `status`, `computer_software(computer_id, name)` +
  `source`, `computers(last_seen_at)`, browser-policy uniques. The batch
  context removed the query patterns that would have needed more.
- **Caching** — settings, winget version lookups and Stripe config are
  already cached; the dashboard aggregates are cheap enough (≤60 ms) that a
  cache layer would add staleness for no visible gain.
- **Queue** — all outbound mail (billing, dunning, reports, alerts)
  implements `ShouldQueue`; run a worker (`php artisan queue:work`) as
  documented in BILLING-OPERATIONS.md §5.
- **Memory** — enforcement hydrates one project's computers at a time;
  ~1000 devices ≈ tens of MB peak, well inside default `memory_limit`.
- **Parallel downloads** — agent-side, winget/choco fetch from vendor CDNs
  per machine; the server never proxies binaries, so fleet size does not
  multiply server bandwidth. Catalogue (binary) packages are downloaded
  straight from their `installer_url` by each agent.
- **API** — agent endpoints are the only per-device hot path (measured
  above); the v1 REST API paginates its list endpoints.

## Re-running / stress testing

- Scale: `DB_DATABASE=piodeploy_bench php artisan perf:bench 5000`
- Steady-state only (skip reseed): add `--fresh=0`
- Treat "first pass" as rollout cost, "steady state" as the recurring cost.
