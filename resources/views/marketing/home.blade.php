@extends('marketing.layout')

@section('content')
{{-- ══════════════ HERO ══════════════ --}}
<section class="hero">
    <div class="hero-bg" id="heroBg">
        <div class="hero-grid"></div>
        <div class="blob blob-1" data-depth="0.02"></div>
        <div class="blob blob-2" data-depth="0.035"></div>
        <div class="blob blob-3" data-depth="0.025"></div>
    </div>
    <div class="container">
        <div class="hero-layout">
            <div>
                <span class="eyebrow"><span class="dot"></span> MSP deployment platform</span>
                @php $title = $content->get('home.hero_title'); $words = preg_split('/\s+/', trim($title)); $last = array_key_last($words); @endphp
                <h1 style="margin-top:1.2rem;">
                    <span class="sr-only">{{ $title }}</span>
                    @foreach ($words as $i => $w)
                        <span class="word {{ $i === $last ? 'accent' : '' }}" style="animation-delay:{{ 0.15 + $i*0.07 }}s" aria-hidden="true">{{ $w }}</span>
                    @endforeach
                </h1>
                <p class="lead">{{ $content->get('home.hero_subtitle') }}</p>
                <div class="hero-actions">
                    <a href="{{ route('get-started') }}" class="btn btn-primary btn-lg" data-magnetic>Request access →</a>
                    <a href="#how" class="btn btn-glass btn-lg">See how it works</a>
                </div>
                <div class="hero-stats">
                    <div><div class="n" data-count="1" data-suffix=" agent">1 agent</div><div class="l">silent Windows service</div></div>
                    <div><div class="n" data-count="5" data-suffix=" browsers">5 browsers</div><div class="l">policy-managed like GPO</div></div>
                    <div><div class="n" data-count="100" data-suffix="%">100%</div><div class="l">unattended installs</div></div>
                </div>
            </div>
            <div class="mock-wrap">
                <div class="mock-glow"></div>
                <div class="mock" id="heroMock">
                    <div class="mock-bar"><i></i><i></i><i></i>
                        <span style="margin-left:10px;font-size:.78rem;color:var(--slate-400);font-weight:600;">PioDeploy · Demo Fleet</span>
                        <span class="pill pill-ok" style="margin-left:auto;" data-count="100" data-suffix="% compliant">100% compliant</span>
                    </div>
                    <div class="mock-body">
                        <div class="mock-row"><div class="info"><span class="ic">🌐</span><div><div class="name">Google Chrome</div><div class="meta">Auto-update · latest</div></div></div><span class="pill pill-ok">Compliant</span></div>
                        <div class="mock-row"><div class="info"><span class="ic">🗜️</span><div><div class="name">7-Zip 24.09</div><div class="meta">Install · pinned version</div></div></div><span class="pill pill-run">Running</span></div>
                        <div class="mock-row"><div class="info"><span class="ic">🛡️</span><div><div class="name">Block incognito</div><div class="meta">Browser policy · all browsers</div></div></div><span class="pill pill-ok">Protected</span></div>
                        <div class="mock-row"><div class="info"><span class="ic">🚫</span><div><div class="name">Remove AnyDesk</div><div class="meta">Blocked software</div></div></div><span class="pill pill-warn">1 pending</span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════ MARQUEE ══════════════ --}}
<div class="marquee">
    <div class="marquee-track">
        @php $tech = ['Winget','Chocolatey','MSI','EXE','MSIX','PowerShell','REST API','Windows Service','Intune','GPO','Registry','Scheduled Tasks']; @endphp
        @for ($r = 0; $r < 2; $r++)
            @foreach ($tech as $t)
                <span class="marquee-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>{{ $t }}</span>
            @endforeach
        @endfor
    </div>
</div>

{{-- ══════════════ FEATURES ══════════════ --}}
<section class="section" id="features">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><span class="dot"></span> Everything in one place</span>
            <h2 style="margin-top:1rem;">The control plane for your <span class="accent">Windows fleet</span></h2>
            <p>One lightweight agent per machine. One portal to deploy, enforce and report — for every client.</p>
        </div>
        <div class="features">
            @php
                $features = [
                    ['M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5', 'Silent deployment', 'Install, update, repair or remove any package — winget, Chocolatey, MSI, EXE, MSIX or ZIP — with no windows, prompts or interruption.'],
                    ['M12 22s8-3.6 8-9V5l-8-3-8 3v8c0 5.4 8 9 8 9zM9 12l2 2 4-4', 'Desired-state policies', '"Chrome always latest", "7-Zip 24.09 exactly", "remove Java". Machines that drift are fixed automatically.'],
                    ['M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18', 'Browser lockdown', 'Disable incognito, guest mode, password saving or dev tools across Chrome, Edge, Firefox and Brave — GPO without a domain.'],
                    ['M22 12h-4l-3 9L9 3l-3 9H2', 'Real-time compliance', 'Every machine reports back. See protected, drifted, pending and offline at a glance, drill in, and export to CSV.'],
                    ['M12 8v4l3 3M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20', 'Rings & schedules', 'Roll out to Pilot first, Production after N days. Maintenance windows, retries and per-machine exclusions built in.'],
                    ['M4 4h16v12H4zM8 20h8M12 16v4', 'Multi-tenant', 'Clients, projects and role-based access. Give each customer read-only visibility into their own fleet — nothing more.'],
                ];
            @endphp
            @foreach ($features as [$path,$title,$desc])
                <div class="feature" data-tilt>
                    <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg></div>
                    <h3>{{ $title }}</h3>
                    <p>{{ $desc }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════ WHY / CAPABILITIES ══════════════ --}}
<section class="section alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><span class="dot"></span> Why PioDeploy</span>
            <h2 style="margin-top:1rem;">Enterprise capability, without the enterprise weight</h2>
            <p>Everything an MSP needs to deploy, secure and report on Windows software — from one agent.</p>
        </div>
        <div class="why-grid">
            @php
                $caps = ['Silent deployment','Zero-touch install','Automatic rollback','Version pinning','Real-time compliance','Remote execution','Offline queue','Policy engine','Browser management','Winget support','Chocolatey support','MSI / EXE / MSIX','Custom scripts','Windows services','Registry policies','Scheduled tasks','Deployment rings','Maintenance windows','Software repository','Endpoint inventory','License tracking','Device health','Audit logging','REST API & webhooks','RBAC','SSO ready','Email / webhook alerts','Client portal'];
            @endphp
            @foreach ($caps as $c)
                <div class="why-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>{{ $c }}</div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════ WORKFLOW ══════════════ --}}
<section class="section" id="how">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><span class="dot"></span> Deployment workflow</span>
            <h2 style="margin-top:1rem;">Policy to proof in six steps</h2>
            <p>Set desired state once. The platform makes it so, keeps it that way, and shows you the evidence.</p>
        </div>
        <div class="flow">
            @php
                $flow = [['Create policy','Pick packages and rules'],['Assign devices','By project or ring'],['Deploy','Silently, in the background'],['Monitor','Live compliance'],['Verify','Read-back confirmation'],['Report','Export and alert']];
            @endphp
            @foreach ($flow as $i => [$t,$d])
                <div class="flow-step reveal"><div class="num">{{ $i+1 }}</div><h3>{{ $t }}</h3><p>{{ $d }}</p></div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════ DASHBOARD PREVIEW ══════════════ --}}
<section class="section tinted">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><span class="dot"></span> Live dashboard</span>
            <h2 style="margin-top:1rem;">See your whole fleet at a glance</h2>
            <p>Devices, policies, deployments and compliance — one portal, real numbers.</p>
        </div>
        <div class="dash reveal" id="dash">
            <div class="dash-tabs">
                <div class="dash-tab active" data-tab="devices">Devices</div>
                <div class="dash-tab" data-tab="policies">Policies</div>
                <div class="dash-tab" data-tab="deployments">Deployments</div>
                <div class="dash-tab" data-tab="compliance">Compliance</div>
            </div>
            <div class="dash-body">
                <div class="dash-panel active" data-panel="devices">
                    <div class="dash-metrics">
                        <div class="metric"><div class="v" data-count="1240">1,240</div><div class="k">Endpoints</div></div>
                        <div class="metric"><div class="v" data-count="1198">1,198</div><div class="k">Online</div></div>
                        <div class="metric"><div class="v" data-count="42">42</div><div class="k">Offline</div></div>
                        <div class="metric"><div class="v" data-count="8">8</div><div class="k">Clients</div></div>
                    </div>
                    <div class="dash-row"><span>Windows 11 Pro</span><span>862 devices</span></div>
                    <div class="dash-row"><span>Windows 10 Pro</span><span>318 devices</span></div>
                    <div class="dash-row"><span>Windows Server</span><span>60 devices</span></div>
                </div>
                <div class="dash-panel" data-panel="policies">
                    <div class="dash-row"><span>Auto-update Chrome</span><span class="bar" style="width:180px"><span data-fill="96"></span></span><span>96%</span></div>
                    <div class="dash-row"><span>Install 7-Zip 24.09</span><span class="bar" style="width:180px"><span data-fill="88"></span></span><span>88%</span></div>
                    <div class="dash-row"><span>Block incognito</span><span class="bar" style="width:180px"><span data-fill="99"></span></span><span>99%</span></div>
                    <div class="dash-row"><span>Remove AnyDesk</span><span class="bar" style="width:180px"><span data-fill="74"></span></span><span>74%</span></div>
                </div>
                <div class="dash-panel" data-panel="deployments">
                    <div class="dash-metrics">
                        <div class="metric"><div class="v" data-count="3418">3,418</div><div class="k">Jobs (30d)</div></div>
                        <div class="metric"><div class="v" data-count="3301">3,301</div><div class="k">Succeeded</div></div>
                        <div class="metric"><div class="v" data-count="41">41</div><div class="k">Failed</div></div>
                        <div class="metric"><div class="v" data-count="97" data-suffix="%">97%</div><div class="k">Success rate</div></div>
                    </div>
                    <div class="dash-row"><span>7-Zip · install</span><span style="color:var(--green)">✔ succeeded</span></div>
                    <div class="dash-row"><span>Chrome · update</span><span style="color:var(--green)">✔ succeeded</span></div>
                    <div class="dash-row"><span>VLC · install</span><span style="color:var(--sky)">running</span></div>
                </div>
                <div class="dash-panel" data-panel="compliance">
                    <div class="dash-metrics">
                        <div class="metric"><div class="v" data-count="97" data-suffix="%">97%</div><div class="k">Compliant</div></div>
                        <div class="metric"><div class="v" data-count="2" data-suffix="%">2%</div><div class="k">Drifted</div></div>
                        <div class="metric"><div class="v" data-count="1" data-suffix="%">1%</div><div class="k">Pending</div></div>
                        <div class="metric"><div class="v" data-count="12">12</div><div class="k">Policies</div></div>
                    </div>
                    <div class="dash-row"><span>Browser policies</span><span class="bar" style="width:180px"><span data-fill="99"></span></span><span>99%</span></div>
                    <div class="dash-row"><span>Software policies</span><span class="bar" style="width:180px"><span data-fill="95"></span></span><span>95%</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════ COMPLIANCE + FEED ══════════════ --}}
<section class="section">
    <div class="container">
        <div class="compliance">
            <div>
                <span class="eyebrow"><span class="dot"></span> Always current</span>
                <h2 style="margin:1rem 0 .6rem;">Compliance you can prove</h2>
                <p class="muted" style="font-size:1.05rem;margin-bottom:24px;">Every agent reports its real state. Watch protection climb as machines check in.</p>
                <div class="gauge-wrap">
                    <svg width="0" height="0"><defs><linearGradient id="gaugeGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#14b8a6"/><stop offset="1" stop-color="#0ea5e9"/></linearGradient></defs></svg>
                    @php $gauges = [['Healthy',97],['Protected',99],['Updated',94]]; @endphp
                    @foreach ($gauges as [$cap,$pct])
                        <div class="gauge reveal">
                            <svg width="120" height="120" viewBox="0 0 120 120">
                                <circle class="ring-bg" cx="60" cy="60" r="52" fill="none" stroke-width="12"/>
                                <circle class="ring-fg" cx="60" cy="60" r="52" fill="none" stroke-width="12" stroke-dasharray="326.7" stroke-dashoffset="326.7" data-gauge="{{ $pct }}"/>
                                <text class="label" x="60" y="60" text-anchor="middle" dominant-baseline="central" transform="rotate(90 60 60)" data-count="{{ $pct }}" data-suffix="%">0%</text>
                            </svg>
                            <div class="cap">{{ $cap }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="feed" id="feed">
                <div class="feed-head"><span class="live"></span> Live activity</div>
                <ul class="feed-list" id="feedList"></ul>
            </div>
        </div>
    </div>
</section>

{{-- ══════════════ TESTIMONIALS ══════════════ --}}
<section class="section alt">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><span class="dot"></span> Loved by MSPs</span>
            <h2 style="margin-top:1rem;">What operators say</h2>
            <p>Built for the way MSPs actually run — quiet, hands-off, one portal for every client.</p>
        </div>
        <div class="quotes">
            @php
                $quotes = [
                    ['We pushed the agent through our RMM on a Friday and had every client\'s fleet enrolled by Monday. Incognito is locked down and nobody noticed a thing.', 'D', 'Operations Lead', '600-seat MSP'],
                    ['Desired-state policies mean I stop chasing machines that missed an update. It fixes itself and shows me the 2% that didn\'t.', 'K', 'Automation Engineer', 'Managed IT provider'],
                    ['One portal for deploys, browser policies and compliance across every client. It replaced three tools and a pile of PowerShell.', 'M', 'Owner', 'Boutique MSP'],
                ];
            @endphp
            @foreach ($quotes as [$text, $initial, $name, $role])
                <div class="quote">
                    <div class="stars">★★★★★</div>
                    <p>“{{ $text }}”</p>
                    <div class="who"><div class="av">{{ $initial }}</div><div><div class="n">{{ $name }}</div><div class="r">{{ $role }}</div></div></div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════ PRICING ══════════════ --}}
<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><span class="dot"></span> Pricing</span>
            <h2 style="margin-top:1rem;">Fair, per-machine pricing</h2>
            <p>From <strong>$0.20 per machine</strong> at scale — cheaper than the usual per-machine rates.</p>
        </div>
        @include('marketing.partials.pricing')
    </div>
</section>

{{-- ══════════════ COMPARISON ══════════════ --}}
<section class="section alt">
    <div class="container">
        <div class="section-head"><h2>PioDeploy vs. traditional deployment</h2><p>What you stop doing by hand.</p></div>
        <div class="compare reveal">
            <table>
                <thead><tr><th>Capability</th><th>PioDeploy</th><th>Scripts &amp; GPO</th></tr></thead>
                <tbody>
                    @php $rows = ['Silent, unattended installs','Desired-state enforcement','Automatic rollback','Version pinning','Browser lockdown','Deployment rings','Real-time compliance','Multi-tenant reporting','Zero-touch enrolment']; @endphp
                    @foreach ($rows as $r)
                        <tr><td>{{ $r }}</td><td class="yes">✔</td><td class="no">—</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- ══════════════ FAQ ══════════════ --}}
<section class="section">
    <div class="container">
        <div class="section-head"><h2>Frequently asked</h2></div>
        <div class="faq">
            @php
                $faqs = [
                    ['Is the agent really silent?', 'Yes. It installs as a Windows service running as SYSTEM, and every deployment runs with no windows or prompts — invisible to the logged-in user.'],
                    ['Do I need a domain or imaging server?', 'No. Push the agent once via GPO, Intune or your RMM. It works on standalone and domain-joined machines alike.'],
                    ['Which package types are supported?', 'winget, Chocolatey, MSI, EXE, MSIX, ZIP/portable and custom PowerShell — install, update, repair, remove and version-pin.'],
                    ['Can clients see their own data?', 'Yes. Client-role accounts are scoped to their own projects and machines only, read-only. Everything else stays with your team.'],
                    ['How is it priced?', 'Per machine under management, billed monthly, on a graduated schedule that gets cheaper at scale. See the pricing above.'],
                ];
            @endphp
            @foreach ($faqs as $i => [$q,$a])
                <div class="faq-item {{ $i === 0 ? 'open' : '' }}">
                    <div class="faq-q">{{ $q }} <span class="chev"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span></div>
                    <div class="faq-a" @if($i === 0) style="max-height:200px" @endif><p>{{ $a }}</p></div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══════════════ FINAL CTA ══════════════ --}}
<section class="section">
    <div class="container">
        <div class="cta reveal">
            <div class="shape shape-1"></div><div class="shape shape-2"></div>
            <h2>{{ $content->get('home.cta_title') }}</h2>
            <p>{{ $content->get('home.cta_text') }}</p>
            <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
                <a href="{{ route('get-started') }}" class="btn btn-primary btn-lg">Request access →</a>
                <a href="{{ route('contact') }}" class="btn btn-glass btn-lg">Book a consultation</a>
            </div>
        </div>
    </div>
</section>
@endsection
