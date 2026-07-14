<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased bg-slate-50 min-h-screen grid place-items-center p-6">
    <div class="max-w-md w-full text-center">
        <div class="mx-auto h-14 w-14 rounded-2xl bg-gradient-to-br from-teal-600 to-teal-800 grid place-content-center text-white text-2xl font-extrabold shadow-sm mb-6" aria-hidden="true">P</div>

        <p class="text-5xl font-extrabold text-slate-200 mb-2">404</p>
        <h1 class="text-xl font-bold text-slate-900">This page doesn't exist</h1>
        <p class="mt-2 text-sm text-slate-500">
            The link may be outdated, or the record was deleted. Check the address
            or head back to safety.
        </p>

        <div class="mt-6">
            <a href="{{ auth()->check() ? route('dashboard') : url('/') }}"
               class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 rounded-lg font-semibold text-sm text-white shadow-sm hover:bg-teal-800 transition">
                Back to {{ auth()->check() ? 'dashboard' : 'home' }}
            </a>
        </div>
    </div>
</body>
</html>
