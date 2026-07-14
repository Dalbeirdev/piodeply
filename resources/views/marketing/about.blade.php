@extends('marketing.layout')
@section('title', 'About us — PioDeploy')
@section('meta', 'PioDeploy is built by ' . $company . ' to give MSPs enterprise-grade software deployment and policy control without the enterprise complexity.')

@section('content')
<section class="page-hero">
    <div class="container">
        <span class="eyebrow">About us</span>
        <h1>Built by an MSP, for MSPs</h1>
        <p class="muted" style="max-width:56ch;margin:1rem auto 0;font-size:1.15rem;">
            We got tired of stitching together scripts, GPOs and half a dozen tools to keep client
            fleets patched and compliant. So we built the platform we wanted.
        </p>
    </div>
</section>

<section class="section">
    <div class="container prose">
        <h2>Our story</h2>
        <p>PioDeploy started inside {{ $company }} as an internal tool. Managing software across dozens
            of client sites meant repetitive, error-prone work: chasing down machines that missed an
            update, re-running failed installs, and having no single view of what was actually deployed.</p>
        <p>We wanted the power of enterprise tooling — desired-state configuration, staged rollouts,
            compliance reporting — without needing a domain, an imaging server, or a six-figure contract.
            One lightweight agent, one portal, and everything runs silently in the background.</p>

        <h2>What we believe</h2>
        <div class="value-grid">
            <div class="feature">
                <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2 3 14h9l-1 8 10-12h-9z"/></svg></div>
                <h3>Invisible by default</h3>
                <p>Deployments should never interrupt the person using the machine. Everything runs silently as a system service.</p>
            </div>
            <div class="feature">
                <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-3.6 8-9V5l-8-3-8 3v8c0 5.4 8 9 8 9zM9 12l2 2 4-4"/></svg></div>
                <h3>Secure by design</h3>
                <p>Hashed keys, role-based access, per-tenant isolation and a full audit trail — not bolted on afterwards.</p>
            </div>
            <div class="feature">
                <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7 9 18l-5-5"/></svg></div>
                <h3>Desired state, always</h3>
                <p>Say what you want the fleet to look like. The platform makes it so — and keeps it that way.</p>
            </div>
        </div>

        <h2>Where we're going</h2>
        <p>PioDeploy is actively developed: patch management, dynamic device collections, approval
            workflows and deeper RMM/PSA integrations are on the roadmap. If there's something your
            MSP needs, <a href="{{ route('contact') }}" style="color:var(--teal-700);font-weight:600;">tell us</a> — we
            build for real operators.</p>
    </div>
</section>

<section class="section alt">
    <div class="container">
        <div class="cta">
            <h2>See it on your own fleet</h2>
            <p>Request access and we'll set you up with a trial tenant and the agent for your first project.</p>
            <a href="{{ route('get-started') }}" class="btn btn-primary btn-lg">Request access →</a>
        </div>
    </div>
</section>
@endsection
