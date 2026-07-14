@extends('marketing.layout')
@section('title', 'Brand & logos — PioDeploy')
@section('meta', 'PioDeploy logo variants — mark, lockups, badges and the brand palette. Download the SVGs.')

@section('content')
<section class="page-hero">
    <div class="container">
        <span class="eyebrow">Brand kit</span>
        <h1>Logos &amp; brand</h1>
        <p class="muted" style="max-width:52ch;margin:1rem auto 0;font-size:1.15rem;">
            The PioDeploy deer, in every variant you need. All vector (SVG) — crisp at any size. Click to download.
        </p>
    </div>
</section>

<section class="section">
    <div class="container">
        @php
            $logos = [
                ['piodeploy-logo.svg',         'Primary lockup',     'Deer + wordmark. Use on light backgrounds.', 'light', 320],
                ['piodeploy-logo-reverse.svg', 'Reverse lockup',     'For dark backgrounds and photos.',           'dark',  320],
                ['piodeploy-badge.svg',        'Pill badge',         'Framed lockup for headers and hero areas.',  'light', 300],
                ['piodeploy-badge-dark.svg',   'Pill badge (dark)',  'Framed lockup on dark surfaces.',            'dark',  300],
                ['piodeploy-mark.svg',         'Deer mark',          'Icon only — favicon, app tiles, small use.', 'light', 120],
                ['piodeploy-mark-circle.svg',  'Circle avatar',      'App icon and social profile pictures.',      'light', 120],
            ];
        @endphp
        <div class="features" style="grid-template-columns:repeat(2,1fr);">
            @foreach ($logos as [$file, $name, $desc, $bg, $w])
                <div class="feature" style="text-align:center;">
                    <div style="border-radius:12px;padding:32px;margin-bottom:16px;display:grid;place-content:center;min-height:170px;
                                {{ $bg === 'dark' ? 'background:linear-gradient(160deg,#0f172a,#134e4a);' : 'background:#f8fafc;border:1px solid #e2e8f0;' }}">
                        <img src="{{ asset('img/' . $file) }}" alt="{{ $name }}" style="max-width:{{ $w }}px;width:100%;height:auto;">
                    </div>
                    <h3>{{ $name }}</h3>
                    <p style="min-height:2.6em;">{{ $desc }}</p>
                    <a href="{{ asset('img/' . $file) }}" download
                       class="btn btn-ghost" style="justify-content:center;margin-top:6px;">Download SVG</a>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="section alt">
    <div class="container">
        <div class="section-head"><h2>Brand palette</h2><p>The colours behind the mark.</p></div>
        @php
            $palette = [
                ['Teal 700', '#0f766e', '#fff'], ['Teal 600', '#0d9488', '#fff'], ['Teal 400', '#14b8a6', '#0b1120'],
                ['Antler',   '#e8974e', '#0b1120'], ['Cream', '#fdeecb', '#0b1120'], ['Ink', '#12100f', '#fff'],
                ['Slate 900','#0f172a', '#fff'],
            ];
        @endphp
        <div class="features" style="grid-template-columns:repeat(4,1fr);gap:16px;">
            @foreach ($palette as [$label, $hex, $text])
                <div style="border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:var(--shadow-sm);">
                    <div style="background:{{ $hex }};color:{{ $text }};height:90px;display:grid;place-content:center;font-weight:700;">{{ $hex }}</div>
                    <div style="padding:10px 14px;font-size:.85rem;color:var(--slate-600);background:#fff;">{{ $label }}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

<section class="section">
    <div class="container prose">
        <h2>Usage</h2>
        <ul>
            <li>Keep clear space around the logo equal to the height of the deer's ear.</li>
            <li>Use the <strong>reverse</strong> lockup or a <strong>badge</strong> on dark or busy backgrounds — never the dark wordmark on dark.</li>
            <li>Don't recolour the deer, stretch it, add effects, or separate the mark from the wordmark in the primary lockup.</li>
            <li>Minimum size: the mark reads down to 24&nbsp;px; below that use the circle avatar.</li>
        </ul>
    </div>
</section>
@endsection
