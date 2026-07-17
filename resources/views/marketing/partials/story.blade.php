@php
    /**
     * The origin story, told in motion. Every beat is drawn from the prose in
     * about.blade.php — no invented founders, dates, customers or metrics.
     *
     * The grid is 8x3. Each card carries --i (paint order) and --d (rings from
     * centre), so the build wave can propagate outward from the portal without
     * JS measuring anything. Decorative: the prose beside it carries the meaning.
     */
    $cols = 8;
    $rows = 3;
    $cx = ($cols - 1) / 2;
    $cy = ($rows - 1) / 2;
@endphp

<div class="story-stage" data-story aria-hidden="true">
    <div class="story-portal"><span class="story-portal-dot"></span></div>
    <div class="story-spine"></div>

    <div class="story-grid">
        @for ($r = 0; $r < $rows; $r++)
            @for ($c = 0; $c < $cols; $c++)
                @php
                    $i = $r * $cols + $c;
                    $d = round(sqrt((($c - $cx) ** 2) + ((($r - $cy) * 1.6) ** 2)));
                    // A stable pseudo-random spread so the same few machines
                    // keep failing — the ones you learn the hostname of.
                    $stubborn = in_array($i, [3, 9, 14, 20], true);
                @endphp
                <span class="m {{ $stubborn ? 'm-stubborn' : '' }}"
                      style="--i:{{ $i }};--d:{{ $d }}"></span>
            @endfor
        @endfor
    </div>

    <div class="story-slabs">
        <span class="slab">A domain</span>
        <span class="slab">An imaging server</span>
        <span class="slab">A six-figure contract</span>
    </div>

    <p class="story-caption" data-story-caption>One site. Then dozens.</p>
</div>

<script>
(function () {
    var stage = document.querySelector('[data-story]');
    if (!stage) return;

    var caption = stage.querySelector('[data-story-caption]');
    var reduce  = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Beats, in the order the story is told. The last one is deliberately long:
    // the product's promise is that nothing happens, and that needs room.
    var PHASES = [
        { cls: 'is-gather',  ms: 5000, text: 'One site. Then dozens.' },
        { cls: 'is-repeat',  ms: 7000, text: 'The same fix. By hand. Again.' },
        { cls: 'is-refuse',  ms: 4200, text: 'The cure cost more than the problem.' },
        { cls: 'is-build',   ms: 6000, text: 'One agent. One portal.' },
        { cls: 'is-silence', ms: 6500, text: 'Then nothing happened.' },
    ];

    // Reduced motion, or no JS-driven story wanted: show the ending. It is the
    // only frame that means anything on its own.
    if (reduce) {
        stage.classList.add('is-silence', 'is-static');
        caption.textContent = PHASES[PHASES.length - 1].text;
        return;
    }

    var at = 0, timer = null, running = false;

    function paint() {
        PHASES.forEach(function (p) { stage.classList.remove(p.cls); });
        stage.classList.add(PHASES[at].cls);
        caption.textContent = PHASES[at].text;
    }

    function tick() {
        paint();
        timer = setTimeout(function () {
            at = (at + 1) % PHASES.length;
            tick();
        }, PHASES[at].ms);
    }

    function start() {
        if (running) return;
        running = true;
        tick();
    }

    function stop() {
        running = false;
        clearTimeout(timer);
    }

    // Never animate a section nobody is looking at, or a backgrounded tab —
    // this runs for 30s on a loop and would otherwise cost someone battery.
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
