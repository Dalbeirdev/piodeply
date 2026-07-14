@extends('marketing.layout')
@section('title', 'Thank you — PioDeploy')

@section('content')
<section class="section">
    <div class="container" style="max-width:640px;text-align:center;padding:100px 24px;">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--teal-50);border:1px solid var(--teal-100);display:grid;place-content:center;margin:0 auto 24px;">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#0f766e" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <h1 style="font-size:2rem;">Subscription confirmed</h1>
        <p class="muted" style="font-size:1.1rem;">Thank you — your payment was successful. We'll email your
            receipt and set up your tenant shortly. If you already have access, you can sign in now.</p>
        <div style="margin-top:24px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <a href="{{ url('/login') }}" class="btn btn-primary btn-lg">Go to the portal →</a>
            <a href="{{ route('home') }}" class="btn btn-ghost btn-lg">Back to home</a>
        </div>
    </div>
</section>
@endsection
