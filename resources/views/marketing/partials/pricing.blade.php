@php
    // Server-rendered example rows keep the table authoritative; the JS
    // calculator mirrors the same graduated schedule for live feedback.
    $examples = [20, 50, 100, 250, 500, 1000, 5000];
    // The market reference (Ninite-style) schedule, for the savings column.
    $ref = function (int $m) {
        $m = max(1, $m); $t = 0; $prev = 0;
        foreach ([[20,100],[500,50],[null,25]] as [$cap,$u]) {
            if ($m <= $prev) break;
            $c = $cap ?? $m; $t += (min($m,$c)-$prev)*$u; $prev = $c;
            if ($cap === null) break;
        }
        return $t;
    };
    $configured = isset($billing) && $billing->isConfigured();
@endphp

<div class="tiers">
    <div class="tier">
        <div class="band">First 20 machines</div>
        <div class="rate">$0.80 <span>/ machine / mo</span></div>
        <div class="was">vs <s>$1.00</s> elsewhere</div>
    </div>
    <div class="tier">
        <div class="band">Next 480 (21–500)</div>
        <div class="rate">$0.40 <span>/ machine / mo</span></div>
        <div class="was">vs <s>$0.50</s> elsewhere</div>
    </div>
    <div class="tier">
        <div class="band">500+ machines</div>
        <div class="rate">$0.20 <span>/ machine / mo</span></div>
        <div class="was">vs <s>$0.25</s> elsewhere</div>
    </div>
</div>

<div class="pricecalc">
    <div class="in" style="flex:1;min-width:260px;">
        <label for="calcMachines">Machines under management: <strong id="calcCount">100</strong></label>
        <input id="calcRange" type="range" min="10" max="5000" step="10" value="100" style="margin:.4rem 0;">
        @if ($configured)
            <form method="POST" action="{{ route('billing.checkout') }}" style="display:flex;align-items:center;gap:12px;margin-top:6px;">
                @csrf
                <input id="calcMachines" name="machines" type="number" min="1" max="100000" value="100" style="width:8rem;">
                <button class="btn btn-primary" type="submit">Subscribe →</button>
            </form>
        @else
            <input id="calcMachines" type="number" min="1" max="100000" value="100" style="width:8rem;margin-top:6px;">
        @endif
    </div>
    @php $defaultCents = isset($billing) ? $billing->quoteCents(100) : 4800; @endphp
    <div class="out">
        <div class="total" id="calcPrice">${{ number_format($defaultCents / 100, 2) }}</div>
        <div class="per"><span id="calcPer">${{ number_format($defaultCents / 100 / 100, 2) }}</span> / machine · billed monthly</div>
    </div>
</div>

@unless ($configured)
    <p class="center muted" style="margin:20px 0 0;">
        <a href="{{ route('get-started') }}" style="color:var(--teal-700);font-weight:600;">Request access →</a>
        to start — online checkout can be enabled by your provider.
    </p>
@endunless

<div class="pd-card" style="margin-top:40px;background:#fff;border:1px solid var(--slate-200);border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow-sm);">
    <div style="overflow-x:auto;">
        <table class="ptable">
            <thead><tr><th>Machines</th><th>PioDeploy / month</th><th>Per machine</th><th>You save*</th></tr></thead>
            <tbody>
                @foreach ($examples as $m)
                    @php
                        $ours = isset($billing) ? $billing->quoteCents($m) : 0;
                        $theirs = $ref($m);
                        $saving = $theirs - $ours;
                    @endphp
                    <tr>
                        <td class="n">{{ number_format($m) }}</td>
                        <td>${{ number_format($ours / 100, 2) }}</td>
                        <td class="muted">${{ number_format($ours / 100 / $m, 2) }}</td>
                        <td class="save">${{ number_format($saving / 100, 2) }}/mo</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
<p class="muted" style="font-size:.82rem;margin-top:12px;">*Compared with a typical $1.00 / $0.50 / $0.25 per-machine schedule. Prices shown in USD; other currencies available.</p>

<script>
(function () {
    var tiers = [[20, 80], [500, 40], [null, 20]];
    function quote(n) {
        n = Math.max(1, parseInt(n) || 0);
        var total = 0, prev = 0;
        for (var i = 0; i < tiers.length; i++) {
            var cap = tiers[i][0], unit = tiers[i][1];
            if (n <= prev) break;
            var c = cap === null ? n : cap;
            total += (Math.min(n, c) - prev) * unit;
            prev = c;
            if (cap === null) break;
        }
        return total;
    }
    var inp = document.getElementById('calcMachines'),
        range = document.getElementById('calcRange'),
        count = document.getElementById('calcCount'),
        price = document.getElementById('calcPrice'),
        per = document.getElementById('calcPer');
    function paint(n) {
        var cents = quote(n);
        if (count) count.textContent = n.toLocaleString();
        price.textContent = '$' + (cents / 100).toFixed(2);
        per.textContent = '$' + (cents / 100 / n).toFixed(2);
    }
    if (inp) {
        inp.addEventListener('input', function () {
            var n = Math.max(1, parseInt(inp.value) || 0);
            if (range && n <= +range.max) range.value = n;
            paint(n);
        });
    }
    if (range) {
        range.addEventListener('input', function () {
            var n = parseInt(range.value);
            if (inp) inp.value = n;
            paint(n);
        });
    }
    paint(100);
})();
</script>
