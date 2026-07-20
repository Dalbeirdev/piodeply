<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-900 leading-tight">Billing</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-5">
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">
                    {{ session('error') }}
                </div>
            @endif

            <div class="pd-card p-6 space-y-4">
                @php
                    $status = $client->subscription_status;
                    $badge = match ($status) {
                        'active', 'trialing' => 'pd-badge-green',
                        'past_due', 'unpaid' => 'pd-badge-amber',
                        'canceled' => 'pd-badge-slate',
                        default => 'pd-badge-slate',
                    };
                @endphp

                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-slate-800">Your subscription</h3>
                    <span class="pd-badge {{ $badge }}"><span class="pd-dot"></span>{{ $status ? str($status)->replace('_', ' ')->ucfirst() : 'Invoiced' }}</span>
                </div>

                <dl class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-slate-400 text-xs uppercase">Plan</dt>
                        <dd class="font-semibold text-slate-800">
                            {{ $client->subscription_machines ? number_format($client->subscription_machines).' machines' : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-xs uppercase">Monthly</dt>
                        <dd class="font-semibold text-slate-800">
                            {{ $client->subscription_cents ? '$'.number_format($client->subscription_cents / 100, 2) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-xs uppercase">Machines enrolled</dt>
                        <dd class="font-semibold text-slate-800">{{ number_format($machineCount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400 text-xs uppercase">{{ $status === 'canceled' ? 'Access until' : 'Renews' }}</dt>
                        <dd class="font-semibold text-slate-800">
                            {{ $client->subscription_period_end?->format('j M Y') ?? '—' }}
                        </dd>
                    </div>
                </dl>

                @if ($status === 'past_due')
                    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-3">
                        Your last payment did not go through. Stripe will retry automatically — or update your card
                        now via <em>Manage billing</em> to settle it immediately. Your fleet keeps working meanwhile.
                    </p>
                @endif

                @if ($client->subscription_machines && $machineCount > $client->subscription_machines)
                    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-3">
                        You have {{ number_format($machineCount) }} machines enrolled against a
                        {{ number_format($client->subscription_machines) }}-machine subscription.
                        Contact us to resize your plan.
                    </p>
                @endif

                <div class="pt-1">
                    <button type="button" wire:click="openPortal"
                            class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                        Manage billing
                    </button>
                    <p class="text-xs text-slate-400 mt-2">
                        Opens Stripe's secure portal — update your card, download invoices, or cancel.
                        Card details never touch PioDeploy's servers.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
