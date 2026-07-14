@extends('marketing.layout')
@section('title', 'Request access — PioDeploy')
@section('meta', 'Request access to PioDeploy. We provision tenants for MSPs — tell us about your fleet and we will get you set up.')

@section('content')
<section class="page-hero">
    <div class="container">
        <span class="eyebrow">Get started</span>
        <h1>Request access</h1>
        <p class="muted" style="max-width:54ch;margin:1rem auto 0;font-size:1.15rem;">
            PioDeploy is provisioned for MSPs — accounts are created by our team, not self-service,
            so your tenant is set up correctly and securely from day one. Tell us about your fleet and
            we'll get you started with a trial tenant and the agent.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="form-card">
            @if (session('lead_ok'))
                <div class="alert-ok">Thanks — your request is in. We'll email you shortly to set up your tenant.</div>
            @endif
            <form method="POST" action="{{ route('leads.store') }}">
                @csrf
                <input type="hidden" name="type" value="access_request">
                <input type="hidden" name="redirect_to" value="get-started">
                <div class="field">
                    <label for="name">Your name</label>
                    <input id="name" name="name" value="{{ old('name') }}" required>
                    @error('name')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="email">Work email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                    @error('email')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="company">MSP / company name</label>
                    <input id="company" name="company" value="{{ old('company') }}" required>
                    @error('company')<div class="err">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="fleet_size">Approximate endpoints under management</label>
                    <select id="fleet_size" name="fleet_size">
                        <option value="">Select…</option>
                        <option @selected(old('fleet_size')==='1-100')>1–100</option>
                        <option @selected(old('fleet_size')==='100-500')>100–500</option>
                        <option @selected(old('fleet_size')==='500-2500')>500–2,500</option>
                        <option @selected(old('fleet_size')==='2500+')>2,500+</option>
                    </select>
                </div>
                <div class="field">
                    <label for="message">Anything we should know? <span class="muted">(optional)</span></label>
                    <textarea id="message" name="message" rows="3">{{ old('message') }}</textarea>
                </div>
                <button class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">Request access →</button>
                <p class="muted" style="text-align:center;font-size:.85rem;margin:14px 0 0;">
                    Already have an account? <a href="{{ url('/login') }}" style="color:var(--teal-700);font-weight:600;">Log in →</a>
                </p>
            </form>
        </div>
    </div>
</section>
@endsection
