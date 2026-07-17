<div wire:poll.15s>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ $policy->name }}</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    {{ $policy->label() }} · {{ $policy->project->name }} · {{ $policy->project->client->company_name }}
                    · {{ ucfirst($policy->status) }}
                    @if ($policy->creator) · created by {{ $policy->creator->name }} @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @can('update', $policy)
                    <a href="{{ route('browser-policies.edit', $policy) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Edit
                    </a>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @can(\App\Enums\Permission::ReportsExport->value)
                <div class="flex justify-end">
                    <x-secondary-button type="button" wire:click="export">Export CSV</x-secondary-button>
                </div>
            @endcan

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                @php
                    $cards = [
                        ['key' => '',              'label' => 'Target',        'value' => $summary['target'],        'tone' => 'text-slate-800'],
                        ['key' => 'compliant',     'label' => 'Protected',     'value' => $summary['protected'],     'tone' => 'text-green-600'],
                        ['key' => 'non_compliant', 'label' => 'Non-compliant', 'value' => $summary['non_compliant'], 'tone' => 'text-red-600'],
                        ['key' => 'pending_restart', 'label' => 'Pending',     'value' => $summary['pending'],       'tone' => 'text-blue-600'],
                        ['key' => 'unsupported',   'label' => 'Unsupported',   'value' => $summary['unsupported'],   'tone' => 'text-slate-500'],
                        ['key' => 'excluded',      'label' => 'Excluded',      'value' => $summary['excluded'],      'tone' => 'text-slate-400'],
                    ];
                @endphp
                @foreach ($cards as $card)
                    <button type="button" wire:click="filterBy('{{ $card['key'] }}')"
                            @class(['pd-card p-4 text-left transition',
                                    'ring-2 ring-teal-500' => $statusFilter === $card['key'] && $card['key'] !== ''])>
                        <p class="text-xs uppercase tracking-wider text-slate-400">{{ $card['label'] }}</p>
                        <p class="text-2xl font-bold {{ $card['tone'] }}">{{ $card['value'] }}</p>
                    </button>
                @endforeach
            </div>

            <div class="pd-card p-4 text-sm text-slate-600">
                <span class="font-semibold">Protection:</span>
                @if ($summary['percent'] === null)
                    <span class="text-slate-400">no computers in scope</span>
                @else
                    @php $pct = $summary['percent']; @endphp
                    <span @class(['font-bold', 'text-green-600' => $pct >= 90, 'text-amber-600' => $pct >= 60 && $pct < 90, 'text-red-600' => $pct < 60])>{{ $pct }}%</span>
                @endif
                @if ($statusFilter !== '')
                    <button type="button" wire:click="filterBy('{{ $statusFilter }}')" class="ml-3 text-xs text-teal-600 hover:underline">Clear filter</button>
                @endif
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Computer</th>
                            <th class="pd-th">Overall</th>
                            @foreach ($browsers as $browser)
                                <th class="pd-th">{{ \App\Enums\Browser::from($browser)->label() }}</th>
                            @endforeach
                            <th class="pd-th">Last checked</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($rows as $row)
                            <tr @class(['opacity-60' => $row['excluded']])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('computers.show', $row['computer']) }}" class="pd-link">{{ $row['computer']->hostname }}</a>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $worstBadge = match ($row['worst']) {
                                            'compliant' => 'bg-green-50 text-green-700 border-green-200',
                                            'pending_restart', 'awaiting' => 'bg-blue-50 text-blue-700 border-blue-200',
                                            'non_compliant', 'error' => 'bg-red-50 text-red-700 border-red-200',
                                            'unsupported' => 'bg-amber-50 text-amber-700 border-amber-200',
                                            default => 'bg-slate-100 text-slate-500 border-slate-200',
                                        };
                                        $worstLabel = match ($row['worst']) {
                                            'compliant' => 'Protected',
                                            'awaiting' => 'Awaiting agent',
                                            'pending_restart' => 'Pending restart',
                                            'non_compliant' => 'Non-compliant',
                                            'not_installed' => 'No browsers',
                                            default => ucfirst(str_replace('_', ' ', $row['worst'])),
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $worstBadge }}">{{ $worstLabel }}</span>
                                </td>
                                @foreach ($browsers as $browser)
                                    @php $result = $row['browsers'][$browser]; @endphp
                                    <td class="px-6 py-3 whitespace-nowrap text-xs"
                                        title="{{ $result?->detail }}">
                                        @if ($result === null)
                                            <span class="text-slate-300">—</span>
                                        @else
                                            <span @class([
                                                'font-medium',
                                                'text-green-600' => $result->status === 'compliant',
                                                'text-red-600' => in_array($result->status, ['non_compliant', 'error'], true),
                                                'text-blue-600' => $result->status === 'pending_restart',
                                                'text-amber-600' => $result->status === 'unsupported',
                                                'text-slate-400' => $result->status === 'not_installed',
                                            ])>{{ str_replace('_', ' ', $result->status) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 text-sm">
                                    @php $last = collect($row['browsers'])->filter()->max('reported_at'); @endphp
                                    {{ $last?->diffForHumans() ?? 'never' }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm">
                                    @can('update', $policy)
                                        <button type="button" wire:click="toggleExclusion({{ $row['computer']->id }})"
                                                class="text-xs {{ $row['excluded'] ? 'text-teal-600' : 'text-slate-400' }} hover:underline">
                                            {{ $row['excluded'] ? 'Include' : 'Exclude' }}
                                        </button>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($browsers) + 4 }}" class="px-6 py-8 text-center text-slate-500">
                                {{ $statusFilter === '' ? 'No computers in this project yet.' : 'No machines match this filter.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{-- Change history --}}
            <div class="pd-card p-6">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Change history</h3>
                @forelse ($history as $entry)
                    <div class="flex items-start gap-3 py-2 {{ ! $loop->last ? 'border-b border-slate-100' : '' }}">
                        <span class="mt-1 h-2 w-2 rounded-full shrink-0 {{ $entry->description === 'created' ? 'bg-teal-500' : 'bg-blue-400' }}"></span>
                        <p class="text-sm text-slate-700">
                            <span class="font-medium">{{ ucfirst(str_replace('_', ' ', $entry->description)) }}</span>
                            <span class="text-slate-400">· {{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at->diffForHumans() }}</span>
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-slate-400">No changes recorded yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
