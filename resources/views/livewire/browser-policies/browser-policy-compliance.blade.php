<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Browser Policy Compliance') }}</h2>
            <a href="{{ route('browser-policies.index') }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                ← All policies
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            {{-- Fleet totals --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold {{ ($fleet['percent'] ?? 0) >= 95 ? 'text-green-600' : 'text-slate-800' }}">
                        {{ $fleet['percent'] !== null ? $fleet['percent'].'%' : '—' }}
                    </p>
                    <p class="text-xs font-semibold text-slate-600">Fleet protected</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">{{ $fleet['policies'] }} active {{ Str::plural('policy', $fleet['policies']) }}</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-green-600">{{ $fleet['protected'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Protected</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold {{ $fleet['non_compliant'] > 0 ? 'text-red-600' : 'text-slate-300' }}">{{ $fleet['non_compliant'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Failing</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold {{ $fleet['pending'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $fleet['pending'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Pending</p>
                    <p class="text-[11px] text-slate-400 mt-0.5">restart or first check-in</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-2xl font-bold text-slate-500">{{ $fleet['unsupported'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Unsupported</p>
                </div>
            </div>

            {{-- Per-policy breakdown --}}
            <div class="pd-card">
                <div class="flex items-center justify-between px-6 pt-4">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">By policy</h3>
                    <label class="flex items-center gap-2 text-sm text-slate-600 select-none">
                        <input type="checkbox" value="1" wire:model.live="onlyProblems" class="rounded border-slate-300">
                        Only policies with problems
                    </label>
                </div>
                <div class="overflow-x-auto mt-2"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Policy</th>
                            <th class="pd-th">Client / Project</th>
                            <th class="pd-th">Compliance</th>
                            <th class="pd-th text-right">Protected</th>
                            <th class="pd-th text-right">Failing</th>
                            <th class="pd-th text-right">Pending</th>
                            <th class="pd-th text-right">Unsupported</th>
                            <th class="pd-th">Last report</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('browser-policies.show', $row['policy']) }}" class="font-medium pd-link">{{ $row['policy']->name }}</a>
                                    <p class="text-xs text-slate-500">{{ $row['policy']->label() }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">
                                    {{ $row['policy']->scopeName() }}
                                    @if ($row['policy']->project !== null)
                                        <p class="text-xs text-slate-500">{{ $row['policy']->project->client->company_name }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <div class="w-28 h-2 rounded-full bg-slate-100 overflow-hidden">
                                            <div class="h-full rounded-full {{ ($row['percent'] ?? 0) >= 95 ? 'bg-green-500' : (($row['percent'] ?? 0) >= 70 ? 'bg-amber-400' : 'bg-red-400') }}"
                                                 style="width: {{ $row['percent'] ?? 0 }}%"></div>
                                        </div>
                                        <span class="text-xs font-semibold text-slate-600">{{ $row['percent'] !== null ? $row['percent'].'%' : '—' }}</span>
                                    </div>
                                    <p class="text-[11px] text-slate-400 mt-0.5">{{ $row['target'] }} targeted</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm text-green-600 font-semibold">{{ $row['protected'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm {{ $row['non_compliant'] > 0 ? 'text-red-600 font-semibold' : 'text-slate-400' }}">{{ $row['non_compliant'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm {{ $row['pending'] > 0 ? 'text-amber-600 font-semibold' : 'text-slate-400' }}">{{ $row['pending'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm text-slate-400">{{ $row['unsupported'] }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-xs text-slate-500">
                                    {{ $row['last_report'] ? \Illuminate\Support\Carbon::parse($row['last_report'])->diffForHumans() : 'never' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-6 py-8 text-center text-slate-500">
                                {{ $onlyProblems === '1' ? 'No policies have problems — everything is protected.' : 'No active browser policies yet.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{-- Machines currently failing --}}
            <div class="pd-card">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide px-6 pt-4">Machines needing attention</h3>
                <div class="overflow-x-auto mt-2"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Computer</th>
                            <th class="pd-th">Policy</th>
                            <th class="pd-th">Browser</th>
                            <th class="pd-th">Status</th>
                            <th class="pd-th">Detail</th>
                            <th class="pd-th">Reported</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($attention as $result)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('computers.show', $result->computer) }}" class="font-medium pd-link">{{ $result->computer->hostname }}</a>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600">{{ $result->policy->name }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600 capitalize">{{ $result->browser }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="inline-flex text-xs font-semibold rounded-full px-2 py-0.5 border bg-red-50 text-red-700 border-red-200">
                                        {{ $result->status === 'error' ? 'Error' : 'Non-compliant' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-xs text-slate-500 max-w-xs truncate" title="{{ $result->detail }}">{{ $result->detail ?: '—' }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-xs text-slate-500">{{ $result->reported_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No machines are failing a browser policy right now.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
