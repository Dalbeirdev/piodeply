<div>
    <x-slot name="header">
        {{-- Static markup only: a header slot renders outside the component,
             so wire:click here would silently never fire. --}}
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <span class="hidden sm:flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700 border border-teal-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                    </svg>
                </span>
                <div>
                    <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Billing & payments') }}</h2>
                    <p class="text-xs text-slate-400 mt-0.5">
                        <a href="{{ route('admin.settings') }}" class="hover:text-slate-600">Settings</a>
                        <span class="mx-1">›</span>
                        <span class="text-slate-500">Billing &amp; payments</span>
                    </p>
                </div>
            </div>
            <a href="https://stripe.com/docs/keys" target="_blank" rel="noopener noreferrer"
               class="hidden sm:inline-flex items-center gap-1.5 text-sm text-slate-400 hover:text-teal-700 shrink-0">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
                </svg>
                Need help?
            </a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @if (session('status'))
                <div class="rounded-xl bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Test-mode keys look identical to live ones on this page, and a
                 site that silently cannot charge anybody is the expensive kind
                 of mistake. Say it plainly rather than in a small blue chip. --}}
            @if ($hasKeys && ! $isLive)
                <div class="rounded-xl bg-amber-50 border border-amber-200 px-4 py-3 flex items-start gap-3">
                    <svg class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <p class="text-sm text-amber-800">
                        <strong>Test mode — no real payments.</strong>
                        These are Stripe test keys, so checkouts succeed on screen but no money moves.
                        Swap in live keys from an activated Stripe account, then re-run
                        <code class="font-mono text-xs bg-amber-100 rounded px-1.5 py-0.5">php artisan billing:sync-stripe</code>
                        to build the catalogue in that account.
                    </p>
                </div>
            @endif

            {{-- Connection status --}}
            <div class="pd-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2.5 flex-wrap">
                            <svg class="h-5 w-5 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                            <h3 class="text-base font-semibold text-slate-800">Stripe connection</h3>
                            <span class="text-xs font-semibold rounded-full px-2.5 py-0.5 border {{ $hasKeys ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200' }}">
                                {{ $hasKeys ? 'Connected' : 'Not connected' }}
                            </span>
                        </div>

                        <div class="flex items-center gap-2 flex-wrap mt-3">
                            @if ($hasKeys)
                                <span class="text-xs font-semibold rounded-full px-2.5 py-1 border bg-green-50 text-green-700 border-green-200">Keys detected</span>
                                <span class="text-xs font-semibold rounded-full px-2.5 py-1 border {{ $isLive ? 'bg-red-50 text-red-700 border-red-200' : 'bg-blue-50 text-blue-700 border-blue-200' }}">
                                    {{ $isLive ? 'LIVE mode' : 'TEST mode' }}
                                </span>
                                <span class="text-xs font-semibold rounded-full px-2.5 py-1 border {{ $hasWebhookSecret ? 'bg-green-50 text-green-700 border-green-200' : 'bg-amber-50 text-amber-700 border-amber-200' }}">
                                    {{ $hasWebhookSecret ? 'Webhook secret set' : 'Webhook secret missing' }}
                                </span>
                            @else
                                <span class="text-xs font-semibold rounded-full px-2.5 py-1 border bg-amber-50 text-amber-700 border-amber-200">
                                    Not configured — enter your keys below
                                </span>
                            @endif
                        </div>
                    </div>

                    <span class="hidden sm:inline-flex items-center gap-2 shrink-0 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-[#635bff] text-white font-bold text-sm leading-none">S</span>
                        <span class="text-sm font-semibold text-slate-600">stripe</span>
                    </span>
                </div>

                <div class="mt-5 pt-4 border-t border-slate-100 space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                        Subscription webhook endpoint
                    </p>
                    <div class="flex items-center gap-2 flex-wrap" x-data="{ copied: false }">
                        <code class="font-mono text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 break-all"
                              x-ref="hook">{{ url('/stripe/webhook') }}</code>
                        <button type="button" class="pd-icon-btn shrink-0"
                                @click="navigator.clipboard.writeText($refs.hook.textContent.trim()); copied = true; setTimeout(() => copied = false, 1500)"
                                :title="copied ? 'Copied' : 'Copy endpoint'">
                            <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                            </svg>
                            <svg x-show="copied" x-cloak class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-slate-500">
                        Add this in Stripe → Developers → Webhooks. After saving keys below, run
                        <code class="font-mono">php artisan billing:sync-stripe</code> once to create the Stripe products &amp; prices.
                    </p>
                </div>

                {{-- Webhook health. Silence here is indistinguishable from a
                     healthy quiet week, which is how a dead endpoint went
                     unnoticed for a month — so say when we last heard from
                     Stripe, out loud. --}}
                @php
                    $tone = match ($health['state']) {
                        'ok'    => ['bg' => 'bg-green-50', 'border' => 'border-green-200', 'text' => 'text-green-800', 'dot' => 'bg-green-500'],
                        'warn'  => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-800', 'dot' => 'bg-amber-500'],
                        default => ['bg' => 'bg-red-50',   'border' => 'border-red-200',   'text' => 'text-red-800',   'dot' => 'bg-red-500'],
                    };
                @endphp
                <div class="mt-5 pt-4 border-t border-slate-100">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Webhook health</p>

                    <div class="rounded-xl {{ $tone['bg'] }} border {{ $tone['border'] }} px-4 py-3">
                        <div class="flex items-start justify-between gap-4 flex-wrap">
                            <div class="flex items-start gap-2.5 min-w-0">
                                <span class="mt-1.5 h-2 w-2 rounded-full shrink-0 {{ $tone['dot'] }}"></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold {{ $tone['text'] }}">{{ $health['headline'] }}</p>
                                    <p class="text-xs {{ $tone['text'] }} opacity-90 mt-0.5">{{ $health['detail'] }}</p>
                                    @if ($health['last'])
                                        <p class="text-xs text-slate-500 mt-1.5">
                                            Last event: <span class="font-mono">{{ $health['last']->type }}</span>
                                            <span class="mx-1">·</span>{{ $health['last']->status }}
                                            <span class="mx-1">·</span>{{ $health['last']->created_at->format('j M Y, H:i') }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button type="button" wire:click="checkEndpoints" wire:loading.attr="disabled"
                                        class="text-sm font-medium text-teal-700 hover:text-teal-800 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="checkEndpoints">Check endpoints</span>
                                    <span wire:loading wire:target="checkEndpoints">Checking…</span>
                                </button>
                                <a href="{{ route('admin.webhooks') }}" class="text-sm font-medium text-slate-500 hover:text-slate-700">Log →</a>
                            </div>
                        </div>
                    </div>

                    @if ($endpointCheck !== null)
                        <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            @if ($endpointCheck['error'])
                                <p class="text-sm text-red-700">Could not reach Stripe: {{ $endpointCheck['error'] }}</p>
                            @else
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">
                                    Endpoints registered in Stripe
                                </p>
                                <ul class="space-y-1.5">
                                    @foreach ($endpointCheck['rows'] as $row)
                                        <li class="flex items-start gap-2 text-sm">
                                            <span class="mt-1 h-2 w-2 rounded-full shrink-0 {{ $row['registered'] ? 'bg-green-500' : 'bg-red-500' }}"></span>
                                            <span class="min-w-0">
                                                <span class="font-mono text-xs break-all">{{ $row['url'] }}</span>
                                                <span class="block text-xs {{ $row['registered'] ? 'text-slate-500' : 'text-red-700 font-medium' }}">
                                                    {{ $row['events'] }} —
                                                    {{ $row['registered'] ? 'registered' : 'MISSING: Stripe is not sending these events' }}
                                                </span>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            {{-- Keys and configuration save together: one form, one button. --}}
            <form wire:submit="save" class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">

                {{-- API keys --}}
                <div class="pd-card p-6 space-y-5">
                    <div class="flex items-center gap-2.5">
                        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                        </svg>
                        <h3 class="text-base font-semibold text-slate-800">Stripe API keys</h3>
                    </div>
                    <p class="text-xs text-slate-500 -mt-2">
                        From Stripe → Developers → API keys. Secrets are encrypted at rest and never shown
                        again — leave a secret field blank to keep the stored value.
                    </p>

                    <div x-data="{ copied: false }">
                        <x-label for="pk" value="Publishable key" />
                        <div class="mt-1 flex items-center gap-2">
                            <x-input id="pk" type="text" class="block w-full font-mono text-sm" wire:model="publishableKey"
                                     placeholder="pk_test_..." autocomplete="off" x-ref="pk" />
                            <button type="button" class="pd-icon-btn shrink-0"
                                    @click="navigator.clipboard.writeText($refs.pk.value); copied = true; setTimeout(() => copied = false, 1500)"
                                    :title="copied ? 'Copied' : 'Copy key'">
                                <svg x-show="!copied" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                                </svg>
                                <svg x-show="copied" x-cloak class="h-4 w-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                </svg>
                            </button>
                        </div>
                        <x-input-error for="publishableKey" class="mt-1" />
                    </div>

                    {{-- Secrets get a reveal toggle (to check what you typed) but no
                         copy button: the stored value is never sent to the browser,
                         so there would be nothing to copy. --}}
                    <div x-data="{ show: false }">
                        <x-label for="sk" value="Secret key" />
                        <div class="mt-1 flex items-center gap-2">
                            <x-input id="sk" type="password" x-bind:type="show ? 'text' : 'password'"
                                     class="block w-full font-mono text-sm" wire:model="secretKey"
                                     placeholder="{{ $hasSecret ? '•••••••• stored — leave blank to keep' : 'sk_test_...' }}" autocomplete="off" />
                            <button type="button" class="pd-icon-btn shrink-0" @click="show = !show" :title="show ? 'Hide' : 'Show'">
                                <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <svg x-show="show" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>
                        <x-input-error for="secretKey" class="mt-1" />
                    </div>

                    <div x-data="{ show: false }">
                        <x-label for="whsec" value="Webhook signing secret" />
                        <div class="mt-1 flex items-center gap-2">
                            <x-input id="whsec" type="password" x-bind:type="show ? 'text' : 'password'"
                                     class="block w-full font-mono text-sm" wire:model="webhookSecret"
                                     placeholder="{{ $hasWebhookSecret ? '•••••••• stored — leave blank to keep' : 'whsec_...' }}" autocomplete="off" />
                            <button type="button" class="pd-icon-btn shrink-0" @click="show = !show" :title="show ? 'Hide' : 'Show'">
                                <svg x-show="!show" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <svg x-show="show" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>
                        <x-input-error for="webhookSecret" class="mt-1" />
                        <p class="text-xs text-slate-500 mt-1">Shown once when you create the webhook endpoint in Stripe.</p>
                    </div>
                </div>

                {{-- Configuration --}}
                <div class="pd-card p-6 space-y-5">
                    <div class="flex items-center gap-2.5">
                        <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.559.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        <h3 class="text-base font-semibold text-slate-800">Configuration</h3>
                    </div>

                    <label class="flex items-start gap-2.5 text-sm text-slate-700">
                        <x-checkbox wire:model="enabled" class="mt-0.5" />
                        <span>
                            Enable the legacy per-machine checkout
                            <span class="block text-xs text-slate-500">on the marketing site (subscription plans do not need this)</span>
                        </span>
                    </label>

                    <div>
                        <x-label for="currency" value="Currency" />
                        <select id="currency" wire:model="currency"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                            {{-- Marked selected server-side rather than relying on
                                 the browser defaulting to the first option: an
                                 operator billing in EUR must never be shown USD. --}}
                            @foreach ($currencies as $code => $name)
                                <option value="{{ $code }}" @selected(strtolower($currency) === $code)>{{ strtoupper($code) }} — {{ $name }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="currency" class="mt-1" />
                        <p class="text-xs text-slate-500 mt-1">The currency every plan and invoice is billed in.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-label for="trialDays" value="Free trial (days)" />
                            <x-input id="trialDays" type="number" min="0" max="365" class="mt-1 block w-full" wire:model="trialDays" />
                            <x-input-error for="trialDays" class="mt-1" />
                            <p class="text-xs text-slate-500 mt-1">
                                Free use before the first charge; a card is still collected up front. Set 0 to charge
                                immediately. This is the number the pricing page promises and the one Stripe is told.
                            </p>
                        </div>
                        <div>
                            <x-label for="clientGraceDays" value="Dunning grace (days)" />
                            <x-input id="clientGraceDays" type="number" min="3" max="60" class="mt-1 block w-full" wire:model="clientGraceDays" />
                            <x-input-error for="clientGraceDays" class="mt-1" />
                            <p class="text-xs text-slate-500 mt-1">
                                How long a client may stay past-due before suspension. Reminders go out every 3 days
                                meanwhile; paying reactivates automatically.
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl bg-slate-50 border border-slate-200 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                            Graduated per-machine schedule (monthly)
                        </p>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            @php $prev = 0; @endphp
                            @foreach ($tiers as $t)
                                <span class="text-xs font-medium bg-white border border-slate-200 rounded-full px-2.5 py-1 text-slate-600">
                                    {{ $t['up_to'] ? ($prev + 1) . '–' . $t['up_to'] : ($prev . '+') }}:
                                    <span class="font-semibold text-slate-800">${{ number_format($t['unit'] / 100, 2) }}</span>
                                </span>
                                @php $prev = $t['up_to'] ?? $prev; @endphp
                            @endforeach
                        </div>
                        <p class="text-xs text-slate-400 mt-2">Defined in code (BillingService::TIERS) to keep pricing consistent with the site.</p>
                    </div>

                    <div class="pt-1">
                        <x-button class="w-full justify-center py-2.5">
                            <svg class="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                            Save billing settings
                        </x-button>
                        <p class="text-xs text-slate-400 text-center mt-2">Saves the keys and the configuration together.</p>
                    </div>
                </div>
            </form>

            {{-- Recent payments --}}
            <div class="pd-card">
                <div class="px-6 pt-5 pb-3 flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="text-base font-semibold text-slate-800">Recent payments</h3>
                    <a href="{{ route('billing.invoices') }}" class="text-sm font-medium text-teal-700 hover:text-teal-800">
                        View all invoices →
                    </a>
                </div>

                {{-- Rows wrap instead of scrolling sideways: a table this wide used
                     to force a horizontal scrollbar on anything but a wide screen. --}}
                <ul class="divide-y divide-slate-100 border-t border-slate-100">
                    @forelse ($payments as $payment)
                        <li class="px-6 py-4 hover:bg-slate-50/60 transition-colors">
                            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                <div class="min-w-0 grow">
                                    <p class="text-sm font-medium text-slate-800 truncate">{{ $payment->customer_email ?? 'Unknown customer' }}</p>
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        {{ $payment->created_at->format('j M Y, H:i') }}
                                        @if ($payment->plan)
                                            <span class="mx-1">·</span>{{ ucfirst($payment->plan) }}
                                        @endif
                                        @if ($payment->quantity)
                                            <span class="mx-1">·</span>{{ $payment->quantity }} machines
                                        @endif
                                    </p>
                                </div>
                                <div class="text-sm font-semibold text-slate-800 tabular-nums">
                                    {{ $payment->amount_total ? strtoupper($payment->currency) . ' ' . number_format($payment->amount_total / 100, 2) : '—' }}
                                </div>
                                <span class="text-xs font-semibold rounded-full px-2.5 py-1 border {{ $payment->status === 'paid' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200' }}">
                                    {{ ucfirst($payment->status) }}
                                </span>
                            </div>
                        </li>
                    @empty
                        <li class="px-6 py-10 text-center">
                            <p class="text-sm text-slate-500">No payments yet.</p>
                            <p class="text-xs text-slate-400 mt-1">Completed checkouts and renewals appear here.</p>
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
