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

        <div class="min-h-screen bg-slate-50">
            @if (session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY))
                <div class="bg-amber-400 text-amber-950">
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex items-center justify-between gap-3 text-sm font-semibold">
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

            @livewire('navigation-menu')

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white border-b border-slate-200/70">
                    <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        @stack('modals')

        @livewireScripts
    </body>
</html>
