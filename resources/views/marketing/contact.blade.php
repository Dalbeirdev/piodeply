@extends('marketing.layout')
@section('title', 'Contact us — PioDeploy')
@section('meta', 'Get in touch with the ' . $company . ' team about PioDeploy — sales, support or partnerships.')

@section('content')
<section class="page-hero">
    <div class="container">
        <span class="eyebrow">Contact</span>
        <h1>Talk to us</h1>
        <p class="muted" style="max-width:50ch;margin:1rem auto 0;font-size:1.15rem;">
            Questions about the platform, pricing or getting your fleet onboarded? We'll get back to you quickly.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="contact-grid">
            <div>
                <div class="contact-item">
                    <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4zM4 6l8 6 8-6"/></svg></div>
                    <div><div class="l">Email</div><div class="v"><a href="mailto:{{ $email }}">{{ $email }}</a></div></div>
                </div>
                <div class="contact-item">
                    <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4.5 8-11a8 8 0 1 0-16 0c0 6.5 8 11 8 11z"/><circle cx="12" cy="11" r="2.5"/></svg></div>
                    <div><div class="l">Company</div><div class="v">{{ $company }}</div></div>
                </div>
                <div class="contact-item">
                    <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z"/></svg></div>
                    <div><div class="l">Response time</div><div class="v">{{ $content->get('contact.response_time') }}</div></div>
                </div>
                <div class="contact-item">
                    <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg></div>
                    <div><div class="l">Already a customer?</div><div class="v"><a href="{{ url('/login') }}">Sign in to the portal →</a></div></div>
                </div>
            </div>

            <div class="form-card">
                @if (session('lead_ok'))
                    <div class="alert-ok">Thanks — we've received your message and will be in touch shortly.</div>
                @endif
                <form method="POST" action="{{ route('leads.store') }}">
                    @csrf
                    <input type="hidden" name="type" value="contact">
                    <input type="hidden" name="redirect_to" value="contact">
                    <div class="field">
                        <label for="name">Name</label>
                        <input id="name" name="name" value="{{ old('name') }}" required>
                        @error('name')<div class="err">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="email">Work email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required>
                        @error('email')<div class="err">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="company">Company</label>
                        <input id="company" name="company" value="{{ old('company') }}">
                        @error('company')<div class="err">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="message">How can we help?</label>
                        <textarea id="message" name="message" rows="4" required>{{ old('message') }}</textarea>
                        @error('message')<div class="err">{{ $message }}</div>@enderror
                    </div>
                    <button class="btn btn-primary btn-lg" style="width:100%;justify-content:center;">Send message</button>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection
