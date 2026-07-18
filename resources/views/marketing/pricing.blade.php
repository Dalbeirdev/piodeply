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
    </div>
</section>

<section class="section alt">
    <div class="container prose">
        <h2 class="center" style="margin-bottom:32px;">Frequently asked</h2>
        @php
            $faqs = [
                ['What counts as an endpoint?', 'Any Windows machine running the PioDeploy agent. You are billed on the number of machines actively enrolled in the month.'],
                ['Is the agent really silent?', 'Yes. It installs as a Windows service running as SYSTEM and every deployment — install, update, remove, browser policy — runs with no windows or prompts, invisible to the logged-in user.'],
                ['Do I need a domain or imaging server?', 'No. Push the agent once through GPO, Intune or your RMM — or run the installer manually. It works on standalone and domain-joined machines alike.'],
                ['Can clients see their own data?', 'Yes. Client-role accounts are scoped to their own projects and machines only, with read-only visibility. Everything else stays with your team.'],
                ['How is my data hosted?', 'Self-host on your own VPS, or we host a managed tenant for you. Enterprise plans can run on a dedicated database or instance.'],
                ['Is there a contract?', 'Starter and Growth are month-to-month. Enterprise plans include an SLA and are priced by volume — talk to us for a quote.'],
            ];
        @endphp
        @foreach ($faqs as [$q,$a])
            <div style="border-bottom:1px solid var(--slate-200);padding:20px 0;">
                <h3 style="margin:0 0 .4rem;">{{ $q }}</h3>
                <p style="margin:0;">{{ $a }}</p>
            </div>
        @endforeach
        <p class="center" style="margin-top:32px;">Still have questions?
            <a href="{{ route('contact') }}" style="color:var(--teal-700);font-weight:600;">Contact us →</a></p>
    </div>
</section>
@endsection
