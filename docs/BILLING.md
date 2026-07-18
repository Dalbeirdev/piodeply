# PioDeploy Billing & Subscriptions

Enterprise subscription billing built into PioDeploy. Built in **Livewire + Blade**
(matching the rest of the app) with **Laravel Cashier** for Stripe. One
subscription belongs to the **MSP account**; a plan's device limit caps the total
number of Computers across that account's projects.

This document grows one phase at a time. Each phase is fully built, tested, and
documented before the next begins.

---

## Architecture

| Concern | Where it lives |
|---|---|
| Plan catalogue | `plans` table, `App\Models\Plan`, `Database\Seeders\PlanSeeder` |
| Pricing logic (single source of truth) | `App\Services\PricingService` |
| Enterprise leads | `enterprise_quotes` + `quote_messages`, `App\Models\EnterpriseQuote` / `QuoteMessage` |
| Public pricing API | `App\Http\Controllers\Api\PricingController` (+ `PlanResource`) |
| Public pricing UI | `resources/views/marketing/partials/plans.blade.php` (marketing site) |
| Quote intake | `MarketingController::storeQuote` → `/quote` |

**Money is always stored and computed in integer cents.** Dollar figures are only
derived at the edges (accessors, resources, views). `PricingService` is the single
place that turns a device count into a price, so a quote shown to a customer can
never disagree with what checkout charges.

### Pricing model

Plans are **tiered, not linear**: a fleet is quoted the smallest plan whose device
limit covers it. Fleets larger than the biggest plan fall through to an
**enterprise quote** (`PricingService::ENTERPRISE_THRESHOLD = 5000`).

Yearly billing is **ten months of the monthly rate** (two months free), so annual
always shows a real saving (~17%).

| Plan | Devices | Monthly | Yearly |
|---|---|---|---|
| 20 Machines | 20 | $16 | $160 |
| 50 Machines | 50 | $28 | $280 |
| 100 Machines ⭐ | 100 | $48 | $480 |
| 250 Machines | 250 | $108 | $1,080 |
| 500 Machines | 500 | $208 | $2,080 |
| 1000 Machines | 1000 | $308 | $3,080 |
| 5000 Machines | 5000 | $1,108 | $11,080 |

⭐ = recommended (badged on the pricing page).

---

## Relationship to the legacy billing code

PioDeploy shipped an earlier **graduated per-machine** checkout
(`App\Services\BillingService`, `BillingController`, the `billing.checkout`
route, `BillingSettings`). It still exists and its tests still pass. Phase 1
replaced only the **public pricing page** with the fixed-plan, trial-first UI;
the legacy graduated checkout is superseded by Cashier in **Phase 3** and will be
retired then.

---

## API

Public, read-only, rate limited (`throttle:60,1`). No customer data is exposed.

### `GET /api/v1/billing/plans`
Returns active plans, cheapest first.
```json
{ "data": [ { "slug": "20-machines", "name": "20 Machines", "device_limit": 20,
  "monthly": 16, "yearly": 160, "per_device": 0.8, "features": [...],
  "is_recommended": false } ] }
```

### `POST /api/v1/billing/pricing/calculate`
Body: `{ "devices": <int 1..1000000> }`. Returns the recommended plan and derived
figures, or `is_enterprise: true` with a null plan above 5000 devices.
```json
{ "data": { "devices": 75, "is_enterprise": false, "plan_name": "100 Machines",
  "monthly": 48, "yearly": 480, "per_device": 0.48, "savings": 96,
  "savings_percent": 17, "plan": { ...PlanResource... } } }
```

---

## Public pages & routes

| Route | What |
|---|---|
| `GET /pricing` | Plan cards (monthly/yearly toggle, recommended badge), device calculator, enterprise card + quote form |
| `POST /quote` | Enterprise quote intake → `enterprise_quotes` + admin notification (rate limited `throttle:6,1`) |

The calculator is vanilla JS mirroring `PricingService` for instant feedback; the
server-side service stays authoritative for checkout. Above 5000 devices the
calculator hides the plan quote and reveals the enterprise quote form.

---

## Database

| Table | Purpose |
|---|---|
| `plans` | Fixed plan catalogue (cents, features JSON, `stripe_*_price_id` filled in Phase 2) |
| `enterprise_quotes` | Requests from fleets over the largest plan; `status`: new → contacted → won/lost |
| `quote_messages` | Internal thread on a quote (a `system` note is seeded on intake) |

Run the plan seeder after deploying (it is idempotent by slug):
```bash
php artisan db:seed --class=PlanSeeder --force
```

---

## Tests

| File | Covers |
|---|---|
| `tests/Feature/PricingServiceTest.php` | Seeded prices, tier recommendation, enterprise threshold, derived figures, edge cases |
| `tests/Feature/BillingPricingApiTest.php` | Plans + calculate endpoints, recommended-plan invariant, validation |
| `tests/Feature/PublicPricingPageTest.php` | Pricing page renders plans/calculator; quote submission + validation |
| `tests/Feature/ContentAndBillingTest.php` | Legacy BillingService + trial-first pricing page |

```bash
php artisan test --filter="PricingServiceTest|BillingPricingApiTest|PublicPricingPageTest"
```

---

## Phase 2 — Cashier, Account billable, card verification, 14-day trial

Billing runs on **Laravel Cashier** (`laravel/cashier` ^16, `stripe/stripe-php`
^17). The Cashier customer is the **`Account`** (the MSP tenant), not a User, so
the Cashier columns (`stripe_id`, `pm_type`, `pm_last_four`, `trial_ends_at`)
live on `accounts`, and `subscriptions.account_id` is the billable FK.
`Cashier::useCustomerModel(Account::class)` is set in `AppServiceProvider`.

### Account (the billing tenant)
`App\Models\Account` — one row per install, `Account::current()` is the accessor.
`use Billable`. Device usage = `Computer::count()` across the whole install;
`effectiveDeviceLimit()` is the admin override if set, else the plan's limit.

### Getting a card on file + starting the trial
1. `Billing\Subscription` Livewire screen (`/billing/subscription`, gated by the
   `manage-billing` gate = `settings.manage`). It creates a **SetupIntent**.
2. Stripe.js (Elements) verifies and tokenises the card in the browser — the
   card number never reaches the server. It returns a payment-method id.
3. `SubscriptionService::startTrial()` rejects **prepaid** cards (fake-account
   defence), attaches the card, and opens a `trialDays(14)` subscription on the
   chosen plan/interval. The customer is not charged until day 14.
4. A `TrialStarted` mail goes to the billing contact.

### Trial lifecycle
- `billing:trial-reminders` (scheduled daily 09:00) emails `TrialEnding` once
  when a trial is within 3 days of ending.
- The charge at trial end and failure→grace→suspend are **Stripe-driven and
  handled by webhooks in Phase 4**.

### Stripe setup (do this once to go live in test mode)
1. Put test keys in `.env`:
   ```
   STRIPE_KEY=pk_test_xxx
   STRIPE_SECRET=sk_test_xxx
   STRIPE_WEBHOOK_SECRET=whsec_xxx   # used in Phase 4
   CASHIER_CURRENCY=usd
   ```
2. Create the Stripe products + prices and backfill the plan IDs:
   ```
   php artisan billing:sync-stripe          # --dry-run to preview
   ```
3. Reload `/billing/subscription`, add a Stripe test card (4242…), start the trial.

### Commands
| Command | Purpose |
|---|---|
| `billing:sync-stripe` | Create/verify Stripe products + monthly/yearly prices; backfill `plans.stripe_*` (idempotent) |
| `billing:trial-reminders` | Email a 3-days-left reminder (scheduled daily) |

### Tests
`tests/Feature/BillingAccountTest.php` (Account limits/override, `applyPlan`,
prepaid rejection via mock, state snapshot), `tests/Feature/TrialLifecycleTest.php`
(reminder windowing + idempotency, page gating, sync command without keys). The
live Stripe trial/SetupIntent path is verified manually in Stripe test mode.

---

## Phase 3 — Checkout & subscription lifecycle

All lifecycle actions live on `SubscriptionService` and the billing screen,
gated by the account's current state.

| Action | Method | Stripe behaviour |
|---|---|---|
| Upgrade / downgrade | `changePlan(account, plan, interval)` | Cashier `swap` — **prorated** automatically |
| Cancel (period end) | `cancel()` | access continues to `ends_at` (grace) |
| Cancel now | `cancelNow()` | ends immediately |
| Resume | `resume()` | only while on grace |
| Pause / unpause | `pause()` / `unpause()` | Stripe `pause_collection` (`void`) |

**Status is derived, never guessed.** `deriveStatus(account)` reads the local
Cashier subscription (+ our `paused_at`) and returns one of
`none · trialing · active · grace · past_due · paused · canceled`. `state()`
exposes `can_change / can_cancel / can_resume / can_pause` so the UI only offers
valid actions. Because derivation reads local columns, it is fully unit-tested
without Stripe (`tests/Feature/BillingLifecycleTest.php`, 12 tests). The swap /
cancel / pause round-trips to Stripe are verified in test mode.

The legacy graduated `BillingService` checkout still exists but is no longer the
path forward; new subscriptions go through the trial + lifecycle above.

---

## Phase 4 — Stripe webhooks

Endpoint: **`POST /stripe/webhook`** (`stripe.webhook`, CSRF-exempt). Cashier's
own routes are disabled (`Cashier::ignoreRoutes()`); this endpoint replaces
them so we control idempotency, logging, and the dashboard.

**Verification → idempotency → handle.** `StripeWebhookController` verifies the
signature (`BillingService::verifyWebhook`, HMAC-SHA256 with 5-min skew), then
records the event in `webhook_events` keyed by Stripe's unique event id. A
redelivered event that was already `processed` returns `200` without re-running.
A handler error is logged and returned `500` so Stripe retries.

`WebhookService` acts on the payload alone (no calls back to Stripe), so the
whole path is testable offline:

| Event | Effect |
|---|---|
| `customer.subscription.created/updated/deleted` | mirror status / `ends_at` / trial onto the local subscription, then re-derive account status |
| `invoice.payment_failed` (retry scheduled) | account → `past_due`, `grace_ends_at` = next attempt, email the billing contact |
| `invoice.payment_failed` (no retry) | account → `suspended` |
| `invoice.paid` | clear the grace window, re-derive status |
| `charge.refunded` | logged for the dashboard |
| `checkout.session.completed`, `payment_intent.*` | acknowledged |
| anything else | recorded as `skipped` |

**Dashboard:** `/admin/webhooks` (Livewire, `settings.manage`) lists every event
with status, error and a one-click **Retry** for failed/received events.

### Stripe dashboard setup
Point the Stripe webhook at `https://<host>/stripe/webhook`, subscribe to the
events above, and put its signing secret in `STRIPE_WEBHOOK_SECRET`.

Tests: `tests/Feature/BillingWebhookTest.php` (9) — signature rejection,
idempotency, each status transition, suspend-on-final-failure, dashboard + retry.

---

## Phase status

- [x] **Phase 1 — Plans, Pricing Calculator, Enterprise Quotes** (no Stripe)
- [x] **Phase 2 — Cashier + Account (Billable) + card verification + 14-day trial**
- [x] **Phase 3 — Checkout + subscription lifecycle (upgrade/downgrade/cancel/resume/pause + proration)**
- [x] **Phase 4 — Stripe webhooks (verify, idempotency, transitions, dashboard)**
- [ ] Phase 5 — Customer billing portal + invoices + payment methods
- [ ] Phase 6 — Device-limit enforcement + email notifications
- [ ] Phase 7 — Coupons
- [ ] Phase 8 — Affiliate system
- [ ] Phase 9 — Admin billing dashboard + admin panel + exports
- [ ] Phase 10 — Security sweep + full test pass + documentation
