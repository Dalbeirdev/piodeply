@php
    /** Fixed plans rendered from the DB. The JS calculator mirrors the same
        tier data (device_limit -> plan) for live feedback; the server-side
        PricingService remains authoritative for checkout. */
    $plansJson = $plans->map(fn ($p) => [
        'slug'         => $p->slug,
        'name'         => $p->name,
        'device_limit' => $p->device_limit,
        'monthly'      => $p->monthly_price_cents,
        'yearly'       => $p->yearly_price_cents,
        'per_device'   => $p->perDeviceCents(),
        'savings'      => $p->yearlySavingsCents(),
    ])->values();
@endphp

@if (session('quote_ok'))
    <div class="notice-ok" style="max-width:640px;margin:0 auto 28px;padding:14px 18px;border-radius:12px;background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;text-align:center;font-weight:600;">
        Thanks — your quote request is in. Our team will be in touch shortly.
    </div>
@endif

{{-- Billing period toggle --}}
<div class="billing-toggle" id="billingToggle" role="tablist" aria-label="Billing period">
    <button type="button" class="bt-opt is-active" data-period="monthly" role="tab" aria-selected="true">Monthly</button>
    <button type="button" class="bt-opt" data-period="yearly" role="tab" aria-selected="false">Yearly <span class="bt-save">2 months free</span></button>
</div>

{{-- Plan cards: only the four headline tiers (≤250) get a card. Larger fleets
     are served by the "Size your plan" calculator and the Enterprise form below,
     which both still read every plan from $plansJson. --}}
<div class="plan-grid">
    @foreach ($plans->take(4) as $plan)
        <div class="plan-card @if ($plan->is_recommended) is-recommended @endif">
            @if ($plan->is_recommended)
                <span class="plan-badge">Most popular</span>
            @endif
            <h3 class="plan-name">{{ $plan->name }}</h3>
            <div class="plan-price">
                <span class="pp-amount"
                      data-monthly="{{ $plan->monthlyPrice() }}"
                      data-yearly="{{ $plan->yearlyPrice() }}">${{ number_format($plan->monthlyPrice(), 0) }}</span>
                <span class="pp-suffix" data-monthly="/ month" data-yearly="/ year">/ month</span>
            </div>
            <p class="plan-per muted">
                <span class="ppd" data-monthly="${{ number_format($plan->perDeviceCents() / 100, 2) }} / machine"
                      data-yearly="${{ number_format($plan->yearly_price_cents / 100 / max(1,$plan->device_limit), 2) }} / machine">${{ number_format($plan->perDeviceCents() / 100, 2) }} / machine</span>
            </p>
            <div class="plan-limit">Up to <strong>{{ number_format($plan->device_limit) }}</strong> machines</div>
            <ul class="plan-features">
                @foreach ($plan->features() as $feature)
                    <li>{{ $feature }}</li>
                @endforeach
            </ul>
            {{-- Straight into the signup wizard, pre-sized to this plan's
                 fleet — the request-access form is the "talk to us" path,
                 not the buy path. --}}
            <a class="btn {{ $plan->is_recommended ? 'btn-primary' : 'btn-ghost' }} plan-cta"
               href="{{ route('signup', ['machines' => $plan->device_limit]) }}">Get started &rarr;</a>
        </div>
    @endforeach
</div>

<p class="center muted" style="margin:18px 0 0;font-size:.9rem;">
    Every plan includes unlimited users and the full platform. 14-day free trial — card required, cancel anytime.<br>
    Managing more than 250 machines? <a href="#calc" style="color:var(--teal-700);font-weight:600;">Size your plan below →</a>
</p>

{{-- Device calculator --}}
<div class="calc-wrap" id="calc">
    <div class="calc-head">
        <h3>Not sure? Size your plan</h3>
        <p class="muted">Enter your machine count and we'll recommend the right plan.</p>
    </div>
    <div class="calc-body">
        <div class="calc-input">
            <label for="calcCount">Machines under management: <strong id="calcCountLabel">100</strong></label>
            <input id="calcRange" type="range" min="10" max="5000" step="10" value="100">
            <input id="calcCount" type="number" min="1" max="10000000" value="100" aria-label="Machine count">
        </div>

        <div class="calc-out" id="calcPlanOut">
            <div class="co-plan">Recommended: <strong id="coPlan">100 Machines</strong></div>
            <div class="co-grid">
                <div><span class="co-label">Monthly</span><span class="co-val" id="coMonthly">$48</span></div>
                <div><span class="co-label">Yearly</span><span class="co-val" id="coYearly">$480</span></div>
                <div><span class="co-label">Per machine</span><span class="co-val" id="coPer">$0.48</span></div>
                <div><span class="co-label">Yearly saving</span><span class="co-val co-save" id="coSave">$96</span></div>
            </div>
            <a class="btn btn-primary" id="coCta" href="{{ route('signup', ['machines' => 100]) }}">Get started &rarr;</a>
        </div>
    </div>
</div>

{{-- Enterprise card + quote form (shown when the fleet exceeds the largest plan) --}}
<div class="enterprise-card" id="enterprise">
    <div class="ent-copy">
        <span class="eyebrow">Enterprise</span>
        <h3>More than {{ number_format($enterpriseThreshold) }} machines?</h3>
        <p class="muted">Custom pricing built around your fleet, with the support an estate this size needs.</p>
        <ul class="ent-features">
            <li>Unlimited devices</li>
            <li>Priority support &amp; custom SLA</li>
            <li>Dedicated account manager</li>
            <li>Custom integrations &amp; SSO</li>
        </ul>
    </div>
    <form class="ent-form" method="POST" action="{{ route('quotes.store') }}">
        @csrf
        <h4>Request a quote</h4>
        @if ($errors->any())
            <div class="form-err">{{ $errors->first() }}</div>
        @endif
        <div class="ef-row">
            <input type="text" name="company_name" placeholder="Company name*" required value="{{ old('company_name') }}">
            <input type="text" name="contact_name" placeholder="Your name*" required value="{{ old('contact_name') }}">
        </div>
        <div class="ef-row">
            <input type="email" name="email" placeholder="Work email*" required value="{{ old('email') }}">
            <input type="text" name="phone" placeholder="Phone" value="{{ old('phone') }}">
        </div>
        <div class="ef-row">
            <input type="text" name="country" placeholder="Country" value="{{ old('country') }}">
            <input type="number" name="device_count" placeholder="Number of devices*" min="1" required
                   value="{{ old('device_count', $enterpriseThreshold + 1) }}">
        </div>
        <div class="ef-row">
            <input type="text" name="current_rmm" placeholder="Current RMM (if any)" value="{{ old('current_rmm') }}">
            <input type="text" name="expected_growth" placeholder="Expected growth" value="{{ old('expected_growth') }}">
        </div>
        <textarea name="notes" placeholder="Anything else we should know?" rows="3">{{ old('notes') }}</textarea>
        <button type="submit" class="btn btn-primary">Request quote →</button>
        <p class="muted" style="font-size:.8rem;margin:.6rem 0 0;">We'll reply within one business day.</p>
    </form>
</div>

<script>
(function () {
    var plans = @json($plansJson);
    var threshold = {{ $enterpriseThreshold }};
    var period = 'monthly';

    var fmt = function (cents, dp) { return '$' + (cents / 100).toFixed(dp === undefined ? 0 : dp); };
    var recommend = function (n) {
        for (var i = 0; i < plans.length; i++) { if (plans[i].device_limit >= n) return plans[i]; }
        return null;
    };

    var range = document.getElementById('calcRange'),
        count = document.getElementById('calcCount'),
        label = document.getElementById('calcCountLabel'),
        planOut = document.getElementById('calcPlanOut'),
        entCard = document.getElementById('enterprise'),
        coPlan = document.getElementById('coPlan'),
        coMonthly = document.getElementById('coMonthly'),
        coYearly = document.getElementById('coYearly'),
        coPer = document.getElementById('coPer'),
        coSave = document.getElementById('coSave'),
        coCta = document.getElementById('coCta'),
        entDevices = document.querySelector('.ent-form input[name="device_count"]');

    function paint(n) {
        n = Math.max(1, parseInt(n) || 1);
        if (label) label.textContent = n.toLocaleString();
        var plan = recommend(n);
        if (!plan) {
            // Enterprise territory: hide the plan quote, surface the quote form.
            planOut.style.display = 'none';
            entCard.classList.add('is-active');
            if (entDevices && document.activeElement !== entDevices) entDevices.value = n;
            return;
        }
        planOut.style.display = '';
        entCard.classList.remove('is-active');
        coPlan.textContent = plan.name;
        coMonthly.textContent = fmt(plan.monthly);
        coYearly.textContent = fmt(plan.yearly);
        if (period === 'monthly') {
            coPer.textContent = fmt(plan.per_device, 2) + ' / mo';
        } else {
            coPer.textContent = '$' + (plan.yearly / 100 / plan.device_limit).toFixed(2) + ' / mo';
        }
        coSave.textContent = fmt(plan.savings) + ' / yr';
        // Carry the actual slider count, not the plan ceiling — the wizard
        // quotes and bills on machines, so what they chose is what they see.
        coCta.setAttribute('href', '{{ route('signup') }}?machines=' + n);
    }

    if (range) range.addEventListener('input', function () { if (count) count.value = range.value; paint(range.value); });
    if (count) count.addEventListener('input', function () {
        var n = Math.max(1, parseInt(count.value) || 1);
        if (range && n <= +range.max) range.value = n;
        paint(n);
    });

    // Monthly / yearly toggle repaints the plan cards and the calculator.
    var toggle = document.getElementById('billingToggle');
    if (toggle) {
        toggle.querySelectorAll('.bt-opt').forEach(function (btn) {
            btn.addEventListener('click', function () {
                period = btn.getAttribute('data-period');
                toggle.querySelectorAll('.bt-opt').forEach(function (b) {
                    var on = b === btn;
                    b.classList.toggle('is-active', on);
                    b.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                document.querySelectorAll('[data-monthly]').forEach(function (el) {
                    var v = el.getAttribute('data-' + period);
                    if (v === null) return;
                    if (el.classList.contains('pp-amount')) el.textContent = '$' + Math.round(parseFloat(v)).toLocaleString();
                    else el.textContent = v;
                });
                paint(count ? count.value : 100);
            });
        });
    }

    paint(100);
})();
</script>
