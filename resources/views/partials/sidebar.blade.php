{{-- Left sidebar: brand, permission-driven nav, profile menu.
     Self-contained Alpine root synced via window events (toggle-sidebar / close-sidebar). --}}
<aside x-data="{
           open: false,
           desktop: window.matchMedia('(min-width: 1024px)').matches,
           init() {
               window.matchMedia('(min-width: 1024px)')
                   .addEventListener('change', e => this.desktop = e.matches);
           },
       }"
       x-show="open || desktop"
       @resize.window="desktop = window.innerWidth >= 1024"
       @toggle-sidebar.window="open = ! open"
       @close-sidebar.window="open = false"
       @keydown.escape.window="open = false"
       class="fixed inset-y-0 left-0 z-40 w-64 bg-white border-r border-slate-200/70 flex flex-col
              lg:static lg:shrink-0">

    {{-- Brand --}}
    <div class="flex items-center gap-2.5 px-5 h-16 border-b border-slate-100 shrink-0">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
            <span class="h-8 w-8 rounded-lg bg-gradient-to-br from-teal-600 to-teal-800 grid place-content-center text-white text-base font-extrabold shadow-sm" aria-hidden="true">P</span>
            <span class="text-[15px] font-bold tracking-tight text-slate-900">PioDeploy
                <span class="font-medium text-slate-400 text-xs">· {{ app(\App\Services\SettingsService::class)->get('branding.company_name') }}</span>
            </span>
        </a>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1" aria-label="Primary">
        @foreach (app(\App\Services\NavigationService::class)->items(Auth::user()) as $item)
            @php $active = request()->routeIs($item['active']); @endphp
            <a href="{{ route($item['route']) }}"
               @if ($active) aria-current="page" @endif
               class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition-colors
                      {{ $active
                          ? 'bg-teal-50 text-teal-800'
                          : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900' }}">
                <svg class="h-[18px] w-[18px] shrink-0 {{ $active ? 'text-teal-700' : 'text-slate-400' }}"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $item['icon'] !!}</svg>
                {{ __($item['label']) }}
            </a>
        @endforeach
    </nav>

    {{-- Profile footer --}}
    <div class="border-t border-slate-100 p-3 shrink-0" x-data="{ profileOpen: false }" @click.outside="profileOpen = false">
        <div x-show="profileOpen" x-cloak
             class="mb-2 rounded-xl border border-slate-200 bg-white shadow-lg p-1.5 text-sm">
            <a href="{{ route('profile.show') }}" class="block px-3 py-2 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-slate-900">Profile</a>
            @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                <a href="{{ route('api-tokens.index') }}" class="block px-3 py-2 rounded-lg text-slate-600 hover:bg-slate-50 hover:text-slate-900">API Tokens</a>
            @endif
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full text-left px-3 py-2 rounded-lg text-slate-600 hover:bg-red-50 hover:text-red-600">Log Out</button>
            </form>
        </div>

        <button type="button" @click="profileOpen = ! profileOpen"
                class="w-full flex items-center gap-3 px-2 py-2 rounded-lg hover:bg-slate-50 transition-colors text-left"
                :aria-expanded="profileOpen" aria-haspopup="menu" aria-label="Account menu">
            <img class="h-9 w-9 rounded-full object-cover shrink-0"
                 src="{{ Auth::user()->profile_photo_url }}" alt="{{ Auth::user()->name }}">
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-slate-800 truncate">{{ Auth::user()->name }}</span>
                <span class="block text-xs text-slate-400 truncate">{{ Auth::user()->email }}</span>
            </span>
            <svg class="h-4 w-4 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round"><path d="m6 15 6-6 6 6"/></svg>
        </button>
    </div>
</aside>

{{-- Mobile overlay --}}
<div x-data="{ open: false }"
     @toggle-sidebar.window="open = ! open"
     @close-sidebar.window="open = false"
     x-show="open" x-cloak
     @click="open = false; $dispatch('close-sidebar')"
     class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" aria-hidden="true"></div>
