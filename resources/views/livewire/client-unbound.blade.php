<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">Dashboard</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="pd-card p-8 text-center">
                <div class="mx-auto mb-4 h-14 w-14 rounded-full bg-amber-50 border border-amber-200 grid place-content-center">
                    <svg class="h-7 w-7 text-amber-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-slate-800 mb-1">Your account isn't linked to a client yet</h3>
                <p class="text-slate-500 max-w-md mx-auto">
                    This is a client account, but it hasn't been assigned to a client organisation.
                    Ask an administrator to bind it to a client from <span class="font-medium">Users → Client binding</span>,
                    and your fleet will appear here.
                </p>
                @if (session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY))
                    <form method="POST" action="{{ route('impersonate.leave') }}" class="mt-6">
                        @csrf
                        <button class="inline-flex items-center px-4 py-2 bg-teal-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-500">
                            Return to my account
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
