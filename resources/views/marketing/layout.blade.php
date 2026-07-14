<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $company . ' — Software deployment & policy management for MSPs')</title>
    <meta name="description" content="@yield('meta', 'Deploy, update and lock down software across your entire Windows fleet from one portal. Silent installs, desired-state policies, real-time compliance.')">
    <link rel="preconnect" href="{{ url('/') }}">
    <link rel="stylesheet" href="{{ asset('css/marketing.css') }}?v=4">
    <link rel="icon" type="image/svg+xml" href="{{ asset('img/piodeploy-mark.svg') }}">
</head>
<body>
    <header class="nav" id="siteNav">
        <div class="container nav-inner">
            <a href="{{ route('home') }}" class="brand">
                <img src="{{ asset('img/piodeploy-mark.svg') }}" class="logo-img" alt="PioDeploy" width="52" height="52">
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
                    <div class="brand"><img src="{{ asset('img/piodeploy-mark.svg') }}" class="logo-img" alt="PioDeploy" width="38" height="38"><span>PioDeploy</span></div>
                    <p class="fdesc">{{ $content->get('footer.tagline') }} Built for MSPs by {{ $company }}.</p>
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
                    <a href="{{ route('brand') }}">Brand &amp; logos</a>
                    <a href="{{ route('contact') }}">Support</a>
                </div>
            </div>
            <div class="footer-bottom">
                <span>© {{ date('Y') }} {{ $company }}. All rights reserved.</span>
                <span>piodeploy — MSP deployment platform</span>
            </div>
        </div>
    </footer>

    <script>
    (function () {
        var nav = document.getElementById('siteNav');
        if (nav) {
            var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 8); };
            window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
        }

        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        var els = document.querySelectorAll('.section-head, .feature, .quote, .step, .tier, .plan, .cta, .pricecalc, .contact-item, .form-card, .ptable');
        els.forEach(function (el, i) { el.classList.add('reveal'); el.style.transitionDelay = (i % 3) * 90 + 'ms'; });

        if (!('IntersectionObserver' in window)) { els.forEach(function (el) { el.classList.add('in'); }); return; }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        els.forEach(function (el) { io.observe(el); });
    })();
    </script>
</body>
</html>
