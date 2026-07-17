<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $company . ' — Software deployment & policy management for MSPs')</title>
    <meta name="description" content="@yield('meta', 'Deploy, update and lock down software across your entire Windows fleet from one portal. Silent installs, desired-state policies, real-time compliance.')">
    <link rel="preconnect" href="{{ url('/') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/marketing.css') }}?v=7">
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
                    <a href="{{ asset('PioDeploy-User-Guide.pdf') }}" target="_blank" rel="noopener">User guide (PDF)</a>
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
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var nav = document.getElementById('siteNav');
        if (nav) {
            var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 8); };
            window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
        }

        function countUp(el) {
            if (el.dataset.done) return; el.dataset.done = '1';
            var target = parseInt(el.dataset.count), suffix = el.dataset.suffix || '';
            if (reduce || !target) { el.textContent = target.toLocaleString() + suffix; return; }
            var start = performance.now(), dur = 1100;
            (function step(now) {
                var p = Math.min(1, (now - start) / dur), val = Math.round(target * (1 - Math.pow(1 - p, 3)));
                el.textContent = val.toLocaleString() + suffix;
                if (p < 1) requestAnimationFrame(step);
            })(start);
        }
        function fillGauge(el) {
            var pct = parseFloat(el.dataset.gauge), circ = 326.7;
            el.style.strokeDashoffset = reduce ? circ * (1 - pct / 100) : circ * (1 - pct / 100);
        }
        function fillBar(el) { el.style.width = el.dataset.fill + '%'; }

        var io = ('IntersectionObserver' in window) ? new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (!e.isIntersecting) return;
                var t = e.target;
                t.classList.add('in');
                t.querySelectorAll('[data-count]').forEach(countUp);
                if (t.matches('[data-count]')) countUp(t);
                t.querySelectorAll('[data-gauge]').forEach(fillGauge);
                t.querySelectorAll('[data-fill]').forEach(fillBar);
                io.unobserve(t);
            });
        }, { threshold: 0.2, rootMargin: '0px 0px -40px 0px' }) : null;

        var reveals = document.querySelectorAll('.section-head, .feature, .why-item, .flow-step, .quote, .tier, .plan, .contact-item, .form-card, .value-grid > *, .gauge, .dash, .compare, .cta, .pricecalc, .metric, [data-count]');
        reveals.forEach(function (el, i) {
            if (!el.matches('[data-count]') && !el.classList.contains('reveal')) { el.classList.add('reveal'); el.style.transitionDelay = (i % 3) * 80 + 'ms'; }
            if (io) io.observe(el); else { el.classList.add('in'); }
        });

        var mock = document.getElementById('heroMock'); if (mock) mock.classList.add('in');

        if (reduce) return;

        var bg = document.getElementById('heroBg');
        if (bg) {
            window.addEventListener('mousemove', function (ev) {
                var x = (ev.clientX / window.innerWidth - 0.5), y = (ev.clientY / window.innerHeight - 0.5);
                bg.querySelectorAll('.blob').forEach(function (b) {
                    var d = parseFloat(b.dataset.depth) * 100;
                    b.style.transform = 'translate(' + (x * d) + 'px,' + (y * d) + 'px)';
                });
            }, { passive: true });
        }

        document.querySelectorAll('[data-tilt]').forEach(function (card) {
            card.addEventListener('mousemove', function (ev) {
                var r = card.getBoundingClientRect(), px = (ev.clientX - r.left) / r.width - 0.5, py = (ev.clientY - r.top) / r.height - 0.5;
                card.style.transform = 'translateY(-8px) rotateX(' + (-py * 6) + 'deg) rotateY(' + (px * 6) + 'deg)';
            });
            card.addEventListener('mouseleave', function () { card.style.transform = ''; });
        });

        var dash = document.getElementById('dash');
        if (dash) {
            var tabs = dash.querySelectorAll('.dash-tab'), panels = dash.querySelectorAll('.dash-panel'), auto = true;
            function activate(name) {
                tabs.forEach(function (t) { t.classList.toggle('active', t.dataset.tab === name); });
                panels.forEach(function (p) { p.classList.toggle('active', p.dataset.panel === name); });
                dash.querySelectorAll('.dash-panel.active [data-fill]').forEach(fillBar);
                dash.querySelectorAll('.dash-panel.active [data-count]').forEach(function (el) { delete el.dataset.done; countUp(el); });
            }
            tabs.forEach(function (t) { t.addEventListener('click', function () { auto = false; activate(t.dataset.tab); }); });
            var order = ['devices', 'policies', 'deployments', 'compliance'], idx = 0;
            setInterval(function () { if (auto) { idx = (idx + 1) % order.length; activate(order[idx]); } }, 4000);
        }

        var feedList = document.getElementById('feedList');
        if (feedList) {
            var events = [
                ['ti', '#0d9488', '#f0fdfa', 'Chrome updated', 'DEMO-PC-01'],
                ['ti', '#0284c7', '#eff6ff', 'Firefox installed', 'ACME-07'],
                ['ti', '#6366f1', '#eef2ff', 'Policy assigned', 'Block incognito'],
                ['ti', '#0d9488', '#f0fdfa', 'Machine online', 'GLOBEX-12'],
                ['ti', '#b45309', '#fffbeb', 'Device locked down', 'FIN-03'],
                ['ti', '#0284c7', '#eff6ff', '7-Zip deployed', 'DEMO-PC-03'],
            ];
            var e = 0;
            function pushEvent() {
                var ev = events[e % events.length]; e++;
                var li = document.createElement('li');
                li.className = 'feed-item feed-enter';
                li.innerHTML = '<span class="fic" style="background:' + ev[2] + ';color:' + ev[1] + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>' +
                    '<div><div style="font-weight:600;color:var(--slate-800);font-size:.88rem">' + ev[3] + '</div><div style="font-size:.78rem;color:var(--slate-400)">' + ev[4] + '</div></div>' +
                    '<span class="ftime">just now</span>';
                feedList.insertBefore(li, feedList.firstChild);
                feedList.querySelectorAll('.ftime').forEach(function (t, i) { if (i > 0) t.textContent = (i * 3 + 2) + 's ago'; });
                while (feedList.children.length > 6) feedList.removeChild(feedList.lastChild);
            }
            for (var k = 0; k < 5; k++) pushEvent();
            setInterval(pushEvent, 3000);
        }

        document.querySelectorAll('.faq-q').forEach(function (q) {
            q.addEventListener('click', function () {
                var item = q.parentElement, a = item.querySelector('.faq-a');
                var open = item.classList.toggle('open');
                a.style.maxHeight = open ? a.scrollHeight + 'px' : '0';
            });
        });
    })();
    </script>
</body>
</html>
