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

                @if ($client->billing_suspended_at !== null)
                    <p class="text-sm text-rose-700 bg-rose-50 border border-rose-200 rounded-md p-3">
                        <b>Your account is suspended for non-payment.</b> Your data and machine history are safe —
                        settling the outstanding payment via <em>Manage billing</em> reactivates everything
                        automatically, no need to contact us.
                    </p>
                @elseif ($status === 'past_due')
                    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-3">
                        Your last payment did not go through. Stripe will retry automatically — or update your card
                        now via <em>Manage billing</em> to settle it immediately. Your fleet keeps working meanwhile.
                    </p>
                @endif

                @if ($client->subscription_machines && $machineCount > $client->subscription_machines)
                    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-3">
                        You have {{ number_format($machineCount) }} machines enrolled against a
                        {{ number_format($client->subscription_machines) }}-machine subscription —
                        resize your plan below to stay covered.
                    </p>
                @endif

                @if ($isOwner && $client->stripe_subscription_id !== null && ! in_array($client->subscription_status, ['canceled', null], true))
                    <div class="border-t border-slate-100 pt-4 space-y-2">
                        <h4 class="text-sm font-semibold text-slate-800">Change plan size</h4>
                        <p class="text-xs text-slate-500">
                            Pick a new machine count — the price difference is prorated automatically on your
                            next invoice (credit when shrinking, charge when growing). Effective immediately.
                        </p>
                        <div class="flex items-center gap-3">
                            <input type="number" min="1" max="100000" wire:model.live.debounce.400ms="resizeMachines"
                                   class="block w-32 text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <span class="text-sm text-slate-600">
                                machines → <b>${{ number_format($resizeQuote / 100, 2) }}/mo</b>
                            </span>
                            <button type="button" wire:click="resize"
                                wire:confirm="Change your subscription to {{ number_format(max(1, $resizeMachines)) }} machines at ${{ number_format($resizeQuote / 100, 2) }}/month? The difference is prorated on your next invoice."
                                class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                                Change plan
                            </button>
                        </div>
                        @error('resizeMachines')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
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
