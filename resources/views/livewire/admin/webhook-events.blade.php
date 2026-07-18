<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Stripe Webhooks') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Status summary + filter --}}
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="$set('statusFilter','')"
                    @class(['px-3 py-1.5 rounded-full text-sm border', 'bg-teal-700 text-white border-teal-700' => $statusFilter==='', 'bg-white border-slate-300 text-slate-600' => $statusFilter!==''])>
                    All
                </button>
                @foreach (['processed','received','skipped','failed'] as $s)
                    <button type="button" wire:click="$set('statusFilter','{{ $s }}')"
                        @class(['px-3 py-1.5 rounded-full text-sm border capitalize', 'bg-teal-700 text-white border-teal-700' => $statusFilter===$s, 'bg-white border-slate-300 text-slate-600' => $statusFilter!==$s])>
                        {{ $s }} <span class="opacity-60">{{ $counts[$s] ?? 0 }}</span>
                    </button>
                @endforeach
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Event</th>
                            <th class="pd-th">Type</th>
                            <th class="pd-th">Status</th>
                            <th class="pd-th">Received</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($events as $event)
                            <tr>
                                <td class="px-6 py-3 font-mono text-xs text-slate-500">{{ $event->stripe_id }}</td>
                                <td class="px-6 py-3 text-sm text-slate-700">{{ $event->type }}</td>
                                <td class="px-6 py-3">
                                    <span @class([
                                        'text-xs font-semibold rounded-full px-2 py-0.5 border capitalize',
                                        'bg-green-50 text-green-700 border-green-200' => $event->status === 'processed',
                                        'bg-slate-100 text-slate-600 border-slate-200' => in_array($event->status, ['received','skipped']),
                                        'bg-red-50 text-red-600 border-red-200' => $event->status === 'failed',
                                    ])>{{ $event->status }}</span>
                                    @if ($event->error)
                                        <p class="text-xs text-red-500 mt-1 max-w-md truncate" title="{{ $event->error }}">{{ $event->error }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-sm text-slate-500 whitespace-nowrap">{{ $event->created_at->diffForHumans() }}</td>
                                <td class="px-6 py-3 text-right">
                                    @if (in_array($event->status, ['failed','received']))
                                        <button type="button" wire:click="retry({{ $event->id }})"
                                            class="text-xs font-semibold text-teal-700 hover:text-teal-900">Retry</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-8 text-center text-slate-500">No webhook events yet.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $events->links() }}
        </div>
    </div>
</div>
