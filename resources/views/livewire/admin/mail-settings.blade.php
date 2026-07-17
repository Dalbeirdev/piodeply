<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Email') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Nothing saved here yet: say what is actually in force, and
                 whether it is the example value that silently fails. --}}
            @if ($usingEnv)
                <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
                    <p class="font-semibold">Email is not configured here yet.</p>
                    <p class="mt-1">
                        The server is using <code class="font-mono">{{ $envHost ?: '(nothing)' }}</code> from its
                        <code class="font-mono">.env</code>.
                        @if (! $envHost || Str::contains(Str::lower($envHost), ['yourprovider', 'example', 'changeme']))
                            <strong>That is the example value, so nothing can send</strong> — deployment failures,
                            offline alerts and website enquiries are all going nowhere. Fill this in to fix it.
                        @else
                            Saving below takes over from it.
                        @endif
                    </p>
                </div>
            @endif

            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <div>
                    <x-label for="provider" value="Provider" />
                    <select id="provider" wire:model.live="provider"
                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        @foreach ($providers as $key => $option)
                            <option value="{{ $key }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 mt-1">
                        Picking one fills in the server details, so you only supply what is yours.
                    </p>
                </div>

                {{-- Every one of these has a gotcha that costs an hour if you
                     do not already know it. Say it before it costs one. --}}
                @if ($preset['warning'])
                    <div class="rounded-md bg-sky-50 border border-sky-200 p-3 text-xs text-sky-900">
                        {{ $preset['warning'] }}
                    </div>
                @endif

                @if ($showServerFields)
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="md:col-span-2">
                            <x-label for="host" value="Host" />
                            <x-input id="host" type="text" class="mt-1 block w-full font-mono text-sm"
                                     wire:model="host" placeholder="smtp.example.com" />
                            <x-input-error for="host" class="mt-1" />
                        </div>
                        <div>
                            <x-label for="port" value="Port" />
                            <x-input id="port" type="number" class="mt-1 block w-full" wire:model="port" />
                            <x-input-error for="port" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-label for="scheme" value="Security" />
                        <select id="scheme" wire:model="scheme"
                                class="mt-1 block w-full md:w-64 border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <option value="tls">STARTTLS (587)</option>
                            <option value="ssl">SSL/TLS (465)</option>
                            <option value="none">None</option>
                        </select>
                    </div>
                @else
                    {{-- The preset knows these. Show what will be used rather
                         than hiding it entirely — then let it be overridden. --}}
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-slate-50 border border-slate-200 px-3 py-2">
                        <span class="text-xs text-slate-500">
                            Connecting to
                            <code class="font-mono text-slate-700">{{ $host }}:{{ $port }}</code>
                            over {{ $scheme === 'ssl' ? 'SSL/TLS' : ($scheme === 'none' ? 'no encryption' : 'STARTTLS') }}
                        </span>
                        <label class="flex items-center gap-1.5 text-xs text-slate-500 select-none">
                            <input type="checkbox" wire:model.live="advanced"
                                   class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            Change server details
                        </label>
                    </div>
                @endif

                <div>
                    <x-label for="username" value="Username" />
                    <x-input id="username" type="text" class="mt-1 block w-full font-mono text-sm"
                             wire:model="username" autocomplete="off" />
                    @if ($preset['username_hint'])
                        <p class="text-xs text-slate-500 mt-1">{{ $preset['username_hint'] }}</p>
                    @endif
                    <x-input-error for="username" class="mt-1" />
                </div>

                <div>
                    <x-label for="password" value="Password" />
                    <x-input id="password" type="password" class="mt-1 block w-full" wire:model="password"
                             autocomplete="new-password"
                             placeholder="{{ $hasPassword ? 'Stored — leave blank to keep it' : ($preset['password_hint'] ?: 'Your SMTP password or API key') }}" />
                    <div class="mt-1 flex items-start justify-between gap-3">
                        <p class="text-xs text-slate-500">
                            {{ $preset['password_hint'] ?: 'Encrypted before it is stored, and never sent back to this page.' }}
                        </p>
                        @if ($hasPassword)
                            <button type="button" wire:click="clearPassword"
                                    wire:confirm="Remove the stored SMTP password? Mail will stop sending until you set a new one."
                                    class="text-xs text-red-600 hover:underline whitespace-nowrap">Remove stored</button>
                        @endif
                    </div>
                    <x-input-error for="password" class="mt-1" />
                </div>

                <hr class="border-slate-100">

                <div>
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Sender</h3>
                    <p class="text-xs text-slate-500 mt-1">
                        Who your emails come from. Most providers reject mail from a domain you have not verified with them.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-label for="from_address" value="From address" />
                        <x-input id="from_address" type="email" class="mt-1 block w-full" wire:model="from_address" />
                        <x-input-error for="from_address" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="from_name" value="From name" />
                        <x-input id="from_name" type="text" class="mt-1 block w-full" wire:model="from_name" />
                        <x-input-error for="from_name" class="mt-1" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <x-button wire:loading.attr="disabled">Save settings</x-button>
                </div>
            </form>

            {{-- Saving proves nothing. Sending does. --}}
            <div class="pd-card p-6 space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Send a test</h3>
                    <p class="text-xs text-slate-500 mt-1">
                        The only way to know these work. Uses the settings above, saved or not.
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-64">
                        <x-label for="testTo" value="Send to" />
                        <x-input id="testTo" type="email" class="mt-1 block w-full" wire:model="testTo" />
                        <x-input-error for="testTo" class="mt-1" />
                    </div>
                    <x-secondary-button wire:click="sendTest" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="sendTest">Send test email</span>
                        <span wire:loading wire:target="sendTest">Sending…</span>
                    </x-secondary-button>
                </div>

                @if ($testSent)
                    <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700">
                        Sent. If it does not arrive within a minute or two, check the spam folder and your
                        provider's own activity log — it left here without complaint.
                    </div>
                @endif

                @if ($testError)
                    <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
                        <p class="font-semibold">It did not send.</p>

                        {{-- Above the evidence, never instead of it: a guess
                             that replaced the provider's own words would be
                             worse than no guess. --}}
                        @if ($testHint)
                            <p class="mt-1.5">{{ $testHint }}</p>
                        @endif

                        <p class="mt-2 text-xs text-red-500">Your provider said:</p>
                        <pre class="mt-1 whitespace-pre-wrap break-words text-xs font-mono">{{ $testError }}</pre>
                    </div>
                @endif
            </div>

            <p class="text-xs text-slate-400">
                Saved here, these take precedence over the server's <code class="font-mono">.env</code> — no SSH,
                no redeploy. Alerts are configured separately under
                <a href="{{ route('admin.notifications') }}" class="pd-link">Notifications</a>.
            </p>
        </div>
    </div>
</div>
