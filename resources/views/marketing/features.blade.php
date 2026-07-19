@extends('marketing.layout')
@section('title', 'Features — PioDeploy')
@section('meta', 'Everything PioDeploy does: silent software deployment, desired-state policies, browser lockdown, real-time compliance, multi-tenant reporting and a full REST API — one agent, one portal.')

@section('content')
<section class="page-hero">
    @include('marketing.partials.hero-bg')
    <div class="container">
        <span class="eyebrow"><span class="dot"></span> Features</span>
        <h1>Everything you need to run a <span class="accent">Windows fleet</span></h1>
        <p class="muted" style="max-width:58ch;margin:1rem auto 0;font-size:1.2rem;">
            One lightweight agent and one portal replace a pile of scripts, GPOs and point tools —
            deploy software, enforce policy, lock down browsers and prove compliance across every client.
        </p>
        <div class="hero-trust">
            <span class="t">One silent agent</span>
            <span class="t">No domain required</span>
            <span class="t">Every client, one portal</span>
        </div>
    </div>
</section>

{{-- Each capability area is a section with a themed intro and three detail
     cards. Copy is drawn from the home page's feature set, expanded. --}}
@php
    $areas = [
        [
            'alt'     => false,
            'eyebrow' => 'Software deployment',
            'title'   => 'Deploy any software, silently',
            'lead'    => 'Install, update, repair, remove or version-pin any package — with no windows, prompts or user interruption.',
            'cards'   => [
                ['M12 2 2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5', 'Every package type',
                    'The full <a href="https://learn.microsoft.com/windows/package-manager/winget/" target="_blank" rel="noopener noreferrer" class="tlink ext">winget</a> catalogue and <a href="https://chocolatey.org/" target="_blank" rel="noopener noreferrer" class="tlink ext">Chocolatey</a>, plus MSI, EXE, <a href="https://learn.microsoft.com/windows/msix/overview" target="_blank" rel="noopener noreferrer" class="tlink ext">MSIX</a>, ZIP/portable and custom PowerShell.'],
                ['M20 6 9 17l-5-5', 'Truly silent', 'Runs as a Windows service under SYSTEM. Deployments happen in the background — invisible to whoever is using the machine.'],
                ['M12 8v4l3 3M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20', 'Install to uninstall', 'One engine handles install, update, repair, remove and exact version-pinning — no separate tools or scripts.'],
            ],
        ],
        [
            'alt'     => true,
            'eyebrow' => 'Desired-state policies',
            'title'   => 'Say what the fleet should look like',
            'lead'    => 'Declare the target state once. Machines that drift are corrected automatically, and kept that way.',
            'cards'   => [
                ['M12 22s8-3.6 8-9V5l-8-3-8 3v8c0 5.4 8 9 8 9zM9 12l2 2 4-4', 'Auto-remediation', '"Chrome always latest", "7-Zip 24.09 exactly", "remove Java". Anything that drifts is put back without you lifting a finger.'],
                ['M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 6v6l4 2', 'Rings & schedules', 'Roll out to a Pilot ring first, Production after N days. Maintenance windows, retries and per-machine exclusions are built in.'],
                ['M4 4h16v12H4zM8 20h8M12 16v4', 'Version control', 'Pin to an exact build, block unwanted software, and enforce it continuously across the whole estate.'],
            ],
        ],
        [
            'alt'     => false,
            'eyebrow' => 'Browser control',
            'title'   => 'GPO-grade browser lockdown, without a domain',
            'lead'    => 'Enforce browser policy across Chrome, Edge, Firefox and Brave — on standalone machines too.',
            'cards'   => [
                ['M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18', 'All major browsers', 'One policy applies across Chrome, Edge, Firefox and Brave — no per-browser admin templates to wrangle.'],
                ['M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11z', 'Lock it down', 'Disable incognito, guest mode, password saving or developer tools — the controls users most often work around.'],
                ['M20 6 9 17l-5-5', 'No domain needed', 'Delivers what Group Policy would, on machines that never touch a domain controller.'],
            ],
        ],
        [
            'alt'     => true,
            'eyebrow' => 'Compliance & reporting',
            'title'   => 'Compliance you can prove',
            'lead'    => 'Every agent reports its real state, so you see the truth — not what you hoped was deployed.',
            'cards'   => [
                ['M22 12h-4l-3 9L9 3l-3 9H2', 'Live status', 'Protected, drifted, pending and offline at a glance. Drill into any machine, policy or deployment in seconds.'],
                ['M4 4h16v16H4zM4 9h16M9 4v16', 'Inventory & audit', 'Full hardware and installed-software inventory per device, plus an audit trail of every sensitive action.'],
                ['M12 3v12m0 0 4-4m-4 4-4-4M4 21h16', 'Export & alert', 'Export compliance to CSV, and get email or webhook alerts when a machine falls out of policy.'],
            ],
        ],
        [
            'alt'     => false,
            'eyebrow' => 'Built for MSPs',
            'title'   => 'Every client, cleanly separated',
            'lead'    => 'Multi-tenant from the ground up — clients, projects and role-based access, with per-tenant isolation.',
            'cards'   => [
                ['M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a4 4 0 0 1 3-3.87M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'Clients & projects', 'Organise fleets by client and project. Scope people and policies to exactly the machines they should touch.'],
                ['M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11z', 'Role-based access', 'Granular RBAC and a full audit log. Give a client read-only visibility into their own fleet — nothing more.'],
                ['M9 18l6-6-6-6', 'Client portal', 'Client-role accounts see their own devices and compliance, read-only. Your team keeps everything else.'],
            ],
        ],
        [
            'alt'     => true,
            'eyebrow' => 'Platform & integrations',
            'title'   => 'Fits how you already work',
            'lead'    => 'Push it through the tools you have, automate it with the API, and host it your way.',
            'cards'   => [
                ['M4 7h16M4 12h16M4 17h10', 'REST API & webhooks', 'Everything the portal does is available over a REST API, with webhooks for real-time events — script and integrate freely.'],
                ['M12 2 4 6v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V6z', 'Push it any way', 'Deploy the agent through GPO, <a href="https://learn.microsoft.com/mem/intune/fundamentals/" target="_blank" rel="noopener noreferrer" class="tlink ext">Intune</a> or your RMM — or run the installer by hand.'],
                ['M4 4h16v12H4zM2 20h20', 'Host your way', 'Self-host on your own VPS, or let us run a managed tenant. SSO-ready for your identity provider.'],
            ],
        ],
    ];
@endphp

@foreach ($areas as $area)
<section class="section {{ $area['alt'] ? 'alt' : '' }}">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><span class="dot"></span> {{ $area['eyebrow'] }}</span>
            <h2 style="margin-top:1rem;">{{ $area['title'] }}</h2>
            <p>{{ $area['lead'] }}</p>
        </div>
        <div class="features">
            @foreach ($area['cards'] as [$path, $title, $desc])
                <div class="feature" data-tilt>
                    <div class="ic"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"/></svg></div>
                    <h3>{{ $title }}</h3>
                    <p>{!! $desc !!}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endforeach

{{-- The full capability list, for the skimmers who want to Ctrl-F. --}}
<section class="section tinted">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow"><span class="dot"></span> Everything in the box</span>
            <h2 style="margin-top:1rem;">The complete capability list</h2>
            <p>Every plan includes the whole platform — no feature gates, no add-ons.</p>
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

{{-- Contrast against the manual way. --}}
<section class="section">
    <div class="container">
        <div class="section-head"><h2>PioDeploy vs. scripts &amp; GPO</h2><p>What you stop doing by hand.</p></div>
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
        <div class="link-row">
            <a href="{{ route('pricing') }}">See pricing →</a>
            <a href="{{ route('about') }}">Why we built it →</a>
            <a href="{{ route('contact') }}">Ask a question →</a>
        </div>
    </div>
</section>

{{-- Final CTA. --}}
<section class="section alt">
    <div class="container">
        <div class="cta reveal">
            <span class="shape shape-1"></span>
            <span class="shape shape-2"></span>
            <h2>See it on your own fleet</h2>
            <p>Request access and we'll set you up with a trial tenant and the agent for your first project.</p>
            <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
                <a href="{{ route('get-started') }}" class="btn btn-primary btn-lg">Request access →</a>
                <a href="{{ route('pricing') }}" class="btn btn-glass btn-lg">View pricing</a>
            </div>
        </div>
    </div>
</section>
@endsection
