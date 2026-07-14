<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">Fleet health report</h2>
            @can(\App\Enums\Permission::ReportsExport->value)
                <button type="button" wire:click="export"
                        class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                    Export CSV
                </button>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="projectFilter" aria-label="Filter by project"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="ringFilter" aria-label="Filter by ring"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All rings</option>
                    @foreach ($rings as $ring)
                        <option value="{{ $ring->value }}">{{ $ring->label() }}</option>
                    @endforeach
                </select>
                <select wire:model.live="presence" aria-label="Filter by presence"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">Online + offline</option>
                    <option value="online">Online only</option>
                    <option value="offline">Offline only</option>
                </select>
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Computer</th>
                            <th class="pd-th">Client / Project</th>
                            <th class="pd-th">Ring</th>
                            <th class="pd-th">Agent</th>
                            <th class="pd-th">Last seen</th>
                            <th class="pd-th">Disk free</th>
                            <th class="pd-th text-right">Software</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($computers as $computer)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('computers.show', $computer) }}" class="pd-link">{{ $computer->hostname }}</a>
                                    <p class="text-xs text-slate-400">{{ $computer->os_name }} {{ $computer->windows_build }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">
                                    {{ $computer->project->client->company_name }} / {{ $computer->project->name }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">{{ $computer->ring->label() }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $computer->isOnline() ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200' }}">
                                        {{ $computer->isOnline() ? 'Online' : 'Offline' }}
                                    </span>
                                    <span class="ml-1 text-xs text-slate-400">{{ $computer->agent_version }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 text-sm" title="{{ $computer->last_seen_at }}">
                                    {{ $computer->last_seen_at?->diffForHumans() ?? 'never' }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm">
                                    @if ($computer->disk_total_bytes && $computer->disk_free_bytes !== null)
                                        @php $diskPct = round($computer->disk_free_bytes / $computer->disk_total_bytes * 100); @endphp
                                        <span class="{{ $diskPct < 10 ? 'text-red-600 font-semibold' : ($diskPct < 20 ? 'text-amber-600' : 'text-slate-600') }}">
                                            {{ $diskPct }}%
                                        </span>
                                        <span class="text-xs text-slate-400">{{ $computer->diskForHumans() }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-slate-600">{{ $computer->software_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No computers match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $computers->links() }}
        </div>
    </div>
</div>
