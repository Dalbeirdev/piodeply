@extends('marketing.layout')
@section('title', 'Pricing — PioDeploy')
@section('meta', 'Simple per-endpoint pricing. Every plan includes the full PioDeploy platform — deployment, policies, browser lockdown, reporting and API.')

@section('content')
<section class="page-hero">
    @include('marketing.partials.hero-bg')
    <div class="container">
        <span class="eyebrow"><span class="dot"></span> Pricing</span>
        <h1>Pay for <span class="accent">what you manage</span></h1>
        <p class="muted" style="max-width:52ch;margin:1rem auto 0;font-size:1.2rem;">{{ $content->get('pricing.intro') }}</p>
        <div class="hero-trust">
            <span class="t">14-day free trial</span>
            <span class="t">No setup fees</span>
            <span class="t">Cancel anytime</span>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        @include('marketing.partials.plans')
        <p class="center muted" style="margin:26px 0 0;font-size:.9rem;display:flex;gap:.5rem;align-items:center;justify-content:center;flex-wrap:wrap;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="color:var(--teal-600)"><path d="M12 22s8-3.6 8-9V5l-8-3-8 3v8c0 5.4 8 9 8 9z"/></svg>
            Payments are processed securely by
            <a href="https://stripe.com/docs/security" target="_blank" rel="noopener noreferrer" class="tlink ext">Stripe</a>.
            Your card details never touch our servers.
        </p>
    </div>
</section>

<section class="section alt">
    <div class="container prose">
        <h2 class="center" style="margin-bottom:32px;">Frequently asked</h2>
        @php
            // Answers carry trusted, author-controlled HTML (internal + outbound
            // links), rendered raw via {!! !!}.
            $faqs = [
                ['What counts as an endpoint?', 'Any Windows machine running the PioDeploy agent. You are billed on the number of machines actively enrolled in the month.'],
                ['Is the agent really silent?', 'Yes. It installs as a Windows service running as SYSTEM and every deployment — install, update, remove, browser policy — runs with no windows or prompts, invisible to the logged-in user.'],
                ['Do I need a domain or imaging server?', 'No. Push the agent once through GPO, <a href="https://learn.microsoft.com/mem/intune/fundamentals/" target="_blank" rel="noopener noreferrer" class="tlink ext">Intune</a> or your RMM — or run the installer manually. It works on standalone and domain-joined machines alike.'],
                ['Which package sources are supported?', 'The full <a href="https://learn.microsoft.com/windows/package-manager/winget/" target="_blank" rel="noopener noreferrer" class="tlink ext">winget</a> catalogue and <a href="https://chocolatey.org/" target="_blank" rel="noopener noreferrer" class="tlink ext">Chocolatey</a>, plus MSI, EXE, MSIX, ZIP and custom PowerShell. See the <a href="'.route('home').'#features" class="tlink">full feature list</a>.'],
                ['Can clients see their own data?', 'Yes. Client-role accounts are scoped to their own projects and machines only, with read-only visibility. Everything else stays with your team.'],
                ['How is my data hosted?', 'Self-host on your own VPS, or we host a managed tenant for you. Enterprise plans can run on a dedicated database or instance — see our <a href="'.route('privacy').'" class="tlink">privacy policy</a>.'],
                ['Is there a contract?', 'The fixed plans are month-to-month. Enterprise plans include an SLA and are priced by volume — <a href="'.route('contact').'" class="tlink">talk to us</a> for a quote.'],
            ];
        @endphp
        @foreach ($faqs as [$q,$a])
            <div style="border-bottom:1px solid var(--slate-200);padding:20px 0;">
                <h3 style="margin:0 0 .4rem;">{{ $q }}</h3>
                <p style="margin:0;">{!! $a !!}</p>
            </div>
        @endforeach
        <p class="center" style="margin-top:32px;">Still have questions?
            <a href="{{ route('contact') }}" class="tlink">Contact us →</a>
            or read <a href="{{ route('about') }}" class="tlink">why we built PioDeploy</a>.</p>
    </div>
</section>
@endsection
