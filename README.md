# PioDeploy Platform

**Automated third-party software deployment & patch-management platform** for MSPs
(TechPio). Multi-tenant Laravel backend + admin/client portals, with a .NET 8
Windows agent on managed endpoints. This is the enterprise successor to the
simple PioDeploy script-generator portal (`C:\xampp\htdocs\PioDeploy`), which
remains a separate app.

## Stack

| Layer | Choice | Notes |
|---|---|---|
| Backend | Laravel 12 · PHP 8.2+ | Repository pattern, service layer, DTOs, API resources |
| DB | MariaDB/MySQL (`piodeploy_platform`) | XAMPP MariaDB 10.4 in dev |
| Queue/Cache | `database` driver in dev | Redis + Horizon on Linux prod (Horizon needs `pcntl`; it cannot run on Windows) |
| Auth | Laravel Sanctum | session (portals) + tokens (agent API) |
| RBAC | spatie/laravel-permission | roles/permissions from Phase 3 |
| Frontend | Livewire + Alpine (from Phase 2) | |
| Agent | .NET 8 Worker Service (Phase 7) | separate `agent/` solution |

## Architecture conventions

- `app/Repositories/Contracts` + `app/Repositories/Eloquent` — data access behind
  interfaces; bindings live in `RepositoryServiceProvider`.
- `app/Services` — business logic; controllers stay thin.
- `app/DTOs` — immutable data carriers (`DataTransferObject` base) between layers.
- `app/Http/Resources` — API response shaping.
- `app/Policies` + Gates — authorization; `app/Enums` — shared enums;
  `app/Jobs`, `app/Events`, `app/Notifications` — async + messaging.

## Dev setup

```bash
composer install
cp .env.example .env && php artisan key:generate
# point DB_* at MariaDB (database: piodeploy_platform), then:
php artisan migrate
php artisan serve
php artisan queue:work        # database queue in dev
```

## Build phases

1. ✅ Project setup & architecture skeleton (this)
2. Authentication (Sanctum, 2FA, profiles, activity log)
3. RBAC (Spatie: Super Admin/Admin/Client/Technician/Viewer/Manager)
4. Client management · 5. Projects · 6. Computer management
7. **Windows agent (.NET 8)** · 8. Software repository · 9. Deployment engine
10. Installer engine · 11. Software detection · 12. Admin dashboard
13. Client dashboard · 14. Policies · 15. Scheduler · 16. Reporting
17. Notifications · 18. Agent REST API · 19. Security hardening · 20. Settings

Each phase lands as reviewed, tested increments — no cross-phase big-bang changes.
