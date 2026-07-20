@extends('marketing.layout')
@section('title', 'Application received — PioDeploy')

@section('content')
<section class="page-hero">
    <div class="container" style="max-width:640px;">
        <span class="eyebrow">Almost there</span>
        <h1>Thanks — your application is in.</h1>
        <p class="muted" style="max-width:52ch;margin:1rem auto 0;font-size:1.1rem;">
            Our team verifies every payment by hand before switching an account on — it usually takes
            less than one business day. The moment your account is approved you'll receive an email
            with your sign-in link. Nothing else is needed from you.
        </p>
        <p style="margin-top:2rem;">
            <a href="{{ route('home') }}" class="btn btn-lg">Back to the site</a>
        </p>
    </div>
</section>
@endsection
