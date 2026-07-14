<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access restricted — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-slate-50 min-h-screen grid place-items-center p-6">
    <div class="max-w-md w-full text-center">
        <div class="mx-auto h-14 w-14 rounded-2xl bg-gradient-to-br from-teal-600 to-teal-800 grid place-content-center text-white text-2xl font-extrabold shadow-sm mb-6" aria-hidden="true">P</div>

        <div class="mx-auto h-12 w-12 rounded-full bg-amber-50 border border-amber-200 grid place-content-center mb-4">
            <svg class="h-6 w-6 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>

        <h1 class="text-xl font-bold text-slate-900">You don't have access to this page</h1>

        @php
            // Spatie's middleware default reads like a stack trace — hide it.
            $reason = $exception->getMessage();
            if (str_starts_with($reason, 'User does not have')) {
                $reason = '';
            }
        @endphp
        <p class="mt-2 text-sm text-slate-500">
            @if ($reason)
                {{ $reason }}
            @elseif (auth()->check())
                Your role doesn't include permission for this area. If you need it,
                ask an administrator to adjust your role.
            @else
                Please sign in to continue.
            @endif
        </p>

        @auth
            <p class="mt-4 text-xs text-slate-400">
                Signed in as <span class="font-semibold text-slate-600">{{ auth()->user()->name }}</span>
                ({{ auth()->user()->getRoleNames()->join(', ') ?: 'no role assigned' }})
            </p>
        @endauth

        <div class="mt-6 flex items-center justify-center gap-3">
            <a href="{{ auth()->check() ? route('dashboard') : route('login') }}"
               class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 rounded-lg font-semibold text-sm text-white shadow-sm hover:bg-teal-800 transition">
                {{ auth()->check() ? 'Back to dashboard' : 'Sign in' }}
            </a>
            @if (session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY))
                <form method="POST" action="{{ route('impersonate.leave') }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-semibold text-sm text-slate-700 hover:bg-slate-50 transition">
                        Return to my account
                    </button>
                </form>
            @elseif (auth()->check())
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-lg font-semibold text-sm text-slate-700 hover:bg-slate-50 transition">
                        Switch account
                    </button>
                </form>
            @endif
        </div>
    </div>
</body>
</html>
