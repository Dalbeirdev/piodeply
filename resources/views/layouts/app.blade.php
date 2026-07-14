<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- System font stack (offline-capable, no external font CDN) -->

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <x-banner />

        @if (session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY))
            <div class="bg-amber-400 text-amber-950 relative z-50">
                <div class="px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-between gap-3 text-sm font-semibold">
                    <span>
                        ⚠ Impersonating <strong>{{ auth()->user()->name }}</strong>
                        ({{ auth()->user()->getRoleNames()->join(', ') ?: 'no role' }}) — actions are performed as this user.
                    </span>
                    <form method="POST" action="{{ route('impersonate.leave') }}">
                        @csrf
                        <button type="submit"
                                class="px-3 py-1 rounded-lg bg-amber-950 text-amber-50 hover:bg-amber-900 text-xs font-bold">
                            Return to my account
                        </button>
                    </form>
                </div>
            </div>
        @endif

        <div class="min-h-screen bg-slate-50 flex">
            @include('partials.sidebar')

            <div class="flex-1 min-w-0 flex flex-col">
                {{-- Mobile top bar --}}
                <div class="lg:hidden flex items-center gap-3 bg-white border-b border-slate-200/70 px-4 h-14 sticky top-0 z-20">
                    <button type="button" x-data @click="$dispatch('toggle-sidebar')"
                            class="pd-icon-btn" aria-label="Open navigation">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <span class="flex items-center gap-2 font-bold text-slate-900 text-[15px]">
                        <span class="h-7 w-7 rounded-lg bg-gradient-to-br from-teal-600 to-teal-800 grid place-content-center text-white text-sm font-extrabold" aria-hidden="true">P</span>
                        PioDeploy
                    </span>
                </div>

                <!-- Page Heading -->
                @if (isset($header))
                    <header class="bg-white border-b border-slate-200/70">
                        <div class="py-5 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif

                <!-- Page Content -->
                <main class="flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @stack('modals')

        @livewireScripts
    </body>
</html>
