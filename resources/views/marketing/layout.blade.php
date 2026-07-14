<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $company . ' — Software deployment & policy management for MSPs')</title>
    <meta name="description" content="@yield('meta', 'Deploy, update and lock down software across your entire Windows fleet from one portal. Silent installs, desired-state policies, real-time compliance.')">
    <link rel="preconnect" href="{{ url('/') }}">
    <link rel="stylesheet" href="{{ asset('css/marketing.css') }}?v=1">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%230f766e'/><text x='16' y='22' font-size='18' font-family='sans-serif' font-weight='700' fill='white' text-anchor='middle'>P</text></svg>">
</head>
<body>
    <header class="nav" id="siteNav">
        <div class="container nav-inner">
            <a href="{{ route('home') }}" class="brand">
                <span class="logo">P</span>
                <span>PioDeploy <span class="sub">· {{ $company }}</span></span>
            </a>
            <nav class="nav-links">
                <a href="{{ route('home') }}#features" class="link">Features</a>
                <a href="{{ route('pricing') }}" class="link">Pricing</a>
                <a href="{{ route('about') }}" class="link">About</a>
                <a href="{{ route('contact') }}" class="link">Contact</a>
            </nav>
            <div class="nav-cta">
                <a href="{{ url('/login') }}" class="btn btn-ghost">Log in</a>
                <a href="{{ route('get-started') }}" class="btn btn-primary">Get started</a>
            </div>
            <button class="nav-toggle" aria-label="Menu"
                    onclick="var n=document.getElementById('siteNav');n.dataset.open=n.dataset.open==='1'?'0':'1';">
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>

    @yield('content')

    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <div class="brand"><span class="logo">P</span><span>PioDeploy</span></div>
                    <p class="fdesc">Centralised software deployment, patch management and browser-policy
                        enforcement for Windows fleets — built for MSPs by {{ $company }}.</p>
                </div>
                <div>
                    <h4>Product</h4>
                    <a href="{{ route('home') }}#features">Features</a>
                    <a href="{{ route('pricing') }}">Pricing</a>
                    <a href="{{ route('home') }}#how">How it works</a>
                    <a href="{{ url('/login') }}">Log in</a>
                </div>
                <div>
                    <h4>Company</h4>
                    <a href="{{ route('about') }}">About us</a>
                    <a href="{{ route('contact') }}">Contact</a>
                    <a href="{{ route('get-started') }}">Request access</a>
                </div>
                <div>
                    <h4>Legal</h4>
                    <a href="{{ route('privacy') }}">Privacy policy</a>
                    <a href="{{ route('contact') }}">Support</a>
                </div>
            </div>
            <div class="footer-bottom">
                <span>© {{ date('Y') }} {{ $company }}. All rights reserved.</span>
                <span>PioDeploy — MSP deployment platform</span>
            </div>
        </div>
    </footer>
</body>
</html>
