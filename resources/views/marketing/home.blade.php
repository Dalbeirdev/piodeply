@extends('marketing.layout')

@section('content')
<section class="hero">
    <div class="container">
        <div class="hero-grid">
            <div>
                <span class="eyebrow">◆ MSP deployment platform</span>
                <h1>{{ $content->get('home.hero_title') }}</h1>
                <p class="lead">{{ $content->get('home.hero_subtitle') }}</p>
                <div class="hero-actions">
                    <a href="{{ route('get-started') }}" class="btn btn-primary btn-lg">Request access →</a>
                    <a href="{{ route('home') }}#how" class="btn btn-light btn-lg">See how it works</a>
                </div>
                <div class="hero-stats">
                    <div><div class="n">1&nbsp;agent</div><div class="l">runs as a silent Windows service</div></div>
                    <div><div class="n">5&nbsp;browsers</div><div class="l">policy-managed like GPO</div></div>
                    <div><div class="n">100%</div><div class="l">unattended installs</div></div>
                </div>
            </div>
            <div class="hero-mock">
                <div class="mock">
                    <div class="mock-bar"><i></i><i></i><i></i></div>
                    <div class="mock-body">
                        <div class="mock-row">
                            <div><div class="name">Google Chrome</div><div class="meta">Auto-update · Demo Fleet</div></div>
                            <span class="pill pill-ok">Compliant</span>
                        </div>
                        <div class="mock-row">
                            <div><div class="name">7-Zip 24.09</div><div class="meta">Install · pinned version</div></div>
                            <span class="pill pill-run">Running</span>
                        </div>
                        <div class="mock-row">
                            <div><div class="name">Block Incognito</div><div class="meta">Browser policy · all browsers</div></div>
                            <span class="pill pill-ok">Protected</span>
                        </div>
                        <div class="mock-row">
                            <div><div class="name">Remove AnyDesk</div><div class="meta">Blocked software</div></div>
                            <span class="pill pill-warn">1 pending</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="trust">
            <span>Winget</span><span>Chocolatey</span><span>MSI</span><span>EXE</span>
            <span>MSIX</span><span>Windows&nbsp;Service</span><span>REST&nbsp;API</span>
        </div>
    </div>
</section>

<section class="section alt" id="features">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow" style="color:var(--teal-700);background:var(--teal-50);border-color:var(--teal-100);">Everything in one place</span>
            <h2>The control plane for your Windows fleet</h2>
            <p>One lightweight agent per machine. One portal to deploy, enforce and report — for every client.</p>
        </div>
        <div class="features">
            @php
                $features = [
                    ['M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5', 'Silent deployment', 'Install, update, repair or remove any package — winget, Chocolatey, MSI, EXE, MSIX or ZIP — with no windows, prompts or user interruption.'],
                    ['M12 22s8-3.6 8-9V5l-8-3-8 3v8c0 5.4 8 9 8 9zM9 12l2 2 4-4', 'Desired-state policies', 'Set the rule once — "Chrome always latest", "7-Zip 24.09 exactly", "remove Java". Machines that drift are fixed automatically.'],
                    ['M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18', 'Browser lockdown', 'Disable incognito, guest mode, password saving or dev tools across Chrome, Edge, Firefox and Brave — enterprise policy without a domain.'],
                    ['M22 12h-4l-3 9L9 3l-3 9H2', 'Real-time compliance', 'Every machine reports back. See protected, drifted, pending and offline at a glance, drill into any policy, export to CSV.'],
                    ['M12 8v4l3 3M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20', 'Rings & schedules', 'Roll out to Pilot first, Production after N days. Maintenance windows, retry logic and per-machine exclusions built in.'],
                    ['M4 4h16v12H4zM8 20h8M12 16v4', 'Multi-tenant', 'Clients, projects and role-based access. Give each customer read-only visibility into their own fleet, nothing more.'],
                ];
            @endphp
            @foreach ($features as [$path,$title,$desc])
                <div class="feature">
                    <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg></div>
                    <h3>{{ $title }}</h3>
                    <p>{{ $desc }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="section" id="how">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow" style="color:var(--teal-700);background:var(--teal-50);border-color:var(--teal-100);">Four steps</span>
            <h2>Live in an afternoon</h2>
            <p>Push the agent through your existing tooling and start deploying — no domain, no imaging, no touching each machine.</p>
        </div>
        <div class="steps">
            <div class="step"><div class="num">1</div><h3>Push the agent</h3><p>One silent command via GPO, Intune or your RMM installs the Windows service as SYSTEM.</p></div>
            <div class="step"><div class="num">2</div><h3>Machines enrol</h3><p>Each agent registers, inventories hardware and software, and appears online within a minute.</p></div>
            <div class="step"><div class="num">3</div><h3>Set policies</h3><p>Choose packages and rules per project. Desired state applies automatically as agents check in.</p></div>
            <div class="step"><div class="num">4</div><h3>Watch compliance</h3><p>Dashboards, reports and alerts show exactly what's deployed, drifted or failing — across every client.</p></div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow" style="color:var(--teal-700);background:var(--teal-50);border-color:var(--teal-100);">Loved by MSPs</span>
            <h2>What operators say</h2>
            <p>Built for the way MSPs actually run — quiet, hands-off, and one portal for every client.</p>
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
                    <div class="who">
                        <div class="av">{{ $initial }}</div>
                        <div><div class="n">{{ $name }}</div><div class="r">{{ $role }}</div></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="section alt">
    <div class="container">
        <div class="section-head">
            <h2>Fair, per-machine pricing</h2>
            <p>Pay only for machines under management — from <strong>$0.20 each</strong> at scale, cheaper than the usual per-machine rates. Every machine includes the full platform.</p>
        </div>
        @include('marketing.partials.pricing')
        <p class="center muted" style="margin-top:28px;">See the full breakdown on the <a href="{{ route('pricing') }}" style="color:var(--teal-700);font-weight:600;">pricing page →</a></p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="cta">
            <h2>{{ $content->get('home.cta_title') }}</h2>
            <p>{{ $content->get('home.cta_text') }}</p>
            <a href="{{ route('get-started') }}" class="btn btn-primary btn-lg">Request access →</a>
        </div>
    </div>
</section>
@endsection
