@php
    /**
     * The origin story, told in motion. Every beat is drawn from the prose in
     * about.blade.php — no invented founders, dates, customers or metrics.
     *
     * The grid is 8x3. Each card carries --i (paint order) and --d (rings from
     * the portal), so the build wave propagates outward without JS measuring
     * anything. The connector fan is an SVG in grid units (viewBox 0 0 8 3),
     * so it stays registered to the cards at any width.
     *
     * Decorative throughout: the prose beside it carries the meaning.
     */
    $cols = 8;
    $rows = 3;
    $cx = ($cols - 1) / 2;
    $cy = ($rows - 1) / 2;

    // A stable handful that keep failing — the ones you learn the hostname of.
    $stubborn = [3, 9, 14, 20];
@endphp

<div class="story-stage" data-story aria-hidden="true">

    {{-- One portal. Using the real mark: this is the thing the line comes from. --}}
    <div class="story-portal">
        <img src="{{ asset('img/piodeploy-mark.svg') }}" alt="" width="20" height="20">
    </div>

    <div class="story-canvas">
        {{-- One line of intent, fanning out to every machine. Grid units, so it
             tracks the cards responsively; strokes stay hairline at any scale. --}}
        <svg class="story-fan" viewBox="0 0 8 3" preserveAspectRatio="none" aria-hidden="true">
            @for ($r = 0; $r < $rows; $r++)
                @for ($c = 0; $c < $cols; $c++)
                    @php
                        $i = $r * $cols + $c;
                        $d = round(sqrt((($c - $cx) ** 2) + ((($r - $cy) * 1.6) ** 2)));
                        $x = $c + 0.5;
                        $y = $r + 0.5;
                    @endphp
                    <path class="fan-line" style="--d:{{ $d }}"
                          pathLength="1" vector-effect="non-scaling-stroke"
                          d="M4,0 Q4,{{ round($y * 0.62, 3) }} {{ $x }},{{ $y }}" />
                @endfor
            @endfor
        </svg>

        <div class="story-grid">
            @for ($r = 0; $r < $rows; $r++)
                @for ($c = 0; $c < $cols; $c++)
                    @php
                        $i = $r * $cols + $c;
                        $d = round(sqrt((($c - $cx) ** 2) + ((($r - $cy) * 1.6) ** 2)));
                    @endphp
                    <span class="m {{ in_array($i, $stubborn, true) ? 'm-stubborn' : '' }}"
                          style="--i:{{ $i }};--d:{{ $d }}">
                        <svg class="m-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>
                        </svg>
                        <span class="m-lines"><i></i><i></i></span>
                        <span class="m-dot"></span>
                        <svg class="m-check" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                    </span>
                @endfor
            @endfor
        </div>
    </div>

    {{-- The turn: the cure existed, and its price was the point. --}}
    <div class="story-slabs">
        <span class="slab">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="4" width="18" height="7" rx="1.5"/><rect x="3" y="13" width="18" height="7" rx="1.5"/>
                <path d="M7 7.5h.01M7 16.5h.01"/>
            </svg>
            A domain
        </span>
        <span class="slab">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>
            </svg>
            An imaging server
        </span>
        <span class="slab">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>
            </svg>
            A six-figure contract
        </span>
    </div>

    <p class="story-caption" data-story-caption>One site. Then dozens.</p>
</div>

<script>
(function () {
    var stage = document.querySelector('[data-story]');
    if (!stage) return;

    var caption = stage.querySelector('[data-story-caption]');
    var reduce  = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Beats, in the order the story is told. The last is deliberately longest:
    // the product's promise is that nothing happens, and that needs room.
    var PHASES = [
        { cls: 'is-gather',  ms: 5000, text: 'One site. Then dozens.' },
        { cls: 'is-repeat',  ms: 7000, text: 'The same fix. By hand. Again.' },
        { cls: 'is-refuse',  ms: 4200, text: 'The cure cost more than the problem.' },
        { cls: 'is-build',   ms: 6000, text: 'One agent. One portal.' },
        { cls: 'is-silence', ms: 6500, text: 'Then nothing happened.' },
    ];

    // Reduced motion: show the ending. It is the only frame that means
    // anything on its own.
    if (reduce) {
        stage.classList.add('is-silence', 'is-static');
        caption.textContent = PHASES[PHASES.length - 1].text;
        return;
    }

    var at = 0, timer = null, running = false;

    function tick() {
        PHASES.forEach(function (p) { stage.classList.remove(p.cls); });
        stage.classList.add(PHASES[at].cls);
        caption.textContent = PHASES[at].text;

        timer = setTimeout(function () {
            at = (at + 1) % PHASES.length;
            tick();
        }, PHASES[at].ms);
    }

    function start() { if (!running) { running = true; tick(); } }
    function stop()  { running = false; clearTimeout(timer); }

    // Never animate a section nobody is looking at, or a backgrounded tab —
    // this loops for 30s and would otherwise cost someone battery.
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
            entries[0].isIntersecting ? start() : stop();
        }, { threshold: 0.25 }).observe(stage);
    } else {
        start();
    }

    document.addEventListener('visibilitychange', function () {
        document.hidden ? stop() : start();
    });
})();
</script>
