<div>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Computers') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">Every machine reporting in, and what it is running.</p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            @php
                $cards = [
                    ['label' => 'Machines', 'value' => $stats['total'],    'sub' => 'Enrolled and reporting',  'tone' => 'teal',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25"/>'],
                    ['label' => 'Online',   'value' => $stats['online'],   'sub' => 'Checked in just now',     'tone' => 'green',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
                    ['label' => 'Offline',  'value' => $stats['offline'],  'sub' => 'Not reporting',           'tone' => $stats['offline'] > 0 ? 'amber' : 'slate',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>'],
                    ['label' => 'Outdated agent', 'value' => $stats['outdated'], 'sub' => 'Below '.\App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION, 'tone' => $stats['outdated'] > 0 ? 'amber' : 'slate',
                     'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>'],
                ];
                $tones = [
                    'teal'  => 'bg-teal-50 text-teal-700 border-teal-100',
                    'green' => 'bg-green-50 text-green-700 border-green-100',
                    'amber' => 'bg-amber-50 text-amber-700 border-amber-100',
                    'slate' => 'bg-slate-100 text-slate-600 border-slate-200',
                ];
            @endphp
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                @foreach ($cards as $card)
                    <div class="pd-card p-4">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $tones[$card['tone']] }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor">{!! $card['icon'] !!}</svg>
                            </span>
                            <p class="text-sm font-semibold text-slate-700 leading-tight">{{ $card['label'] }}</p>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($card['value']) }}</p>
                        <p class="text-xs text-slate-400">{{ $card['sub'] }}</p>
                    </div>
                @endforeach
            </div>

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">
                    {{ session('error') }}
                </div>
            @endif

            <div class="pd-card p-4 flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search hostname, serial, IP, MAC…" aria-label="Search computers"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-80">
                @unless ($isTenant ?? false)
<select wire:model.live="clientId" aria-label="Filter by client"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All clients</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                    @endforeach
                </select>
@endunless
                <select wire:model.live="projectId" aria-label="Filter by {{ project_term_lower() }}"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All {{ project_terms_lower() }}</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="connectivity" aria-label="Filter by connectivity"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">Online + offline</option>
                    <option value="online">Online</option>
                    <option value="offline">Offline</option>
                </select>
                <select wire:model.live="agentStatus" aria-label="Filter by agent version"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">Any agent version</option>
                    <option value="outdated">Agent outdated</option>
                    <option value="current">Agent up to date</option>
                </select>
                @unless ($isTenant ?? false)
<label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="showTrashed" class="rounded border-slate-300">
                    Show deleted
                </label>
@endunless
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Hostname</th>
                            <th class="pd-th">Client / {{ project_term() }}</th>
                            <th class="pd-th">OS</th>
                            <th class="pd-th">Private IP</th>
                            <th class="pd-th">Agent</th>
                            <th class="pd-th">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($computers as $computer)
                            <tr @class(['opacity-60' => $computer->trashed()])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('computers.show', $computer) }}"
                                       class="font-medium pd-link">{{ $computer->hostname }}</a>
                                    @if ($computer->trashed())
                                        <span class="ml-1 text-xs rounded-full bg-red-50 text-red-600 border border-red-200 px-2 py-0.5">deleted</span>
                                    @endif
                                    <p class="text-xs text-slate-500">{{ $computer->serial_number }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">
                                    {{ $computer->project->client->company_name }}
                                    <p class="text-xs text-slate-500">{{ $computer->project->name }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">
                                    {{ $computer->os_name }}
                                    <p class="text-xs text-slate-500">build {{ $computer->windows_build ?? '—' }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 font-mono text-xs">{{ $computer->private_ip ?? '—' }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">
                                    {{ $computer->agent_version ?? '—' }}
                                    @if ($computer->isAgentOutdated())
                                        <span class="ml-1 inline-flex items-center text-xs font-semibold rounded-full px-2 py-0.5 border bg-amber-50 text-amber-700 border-amber-200"
                                              title="Latest is {{ \App\Models\Computer::latestAgentVersion() }} — the agent self-updates on its next check-in">
                                            Update available
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if ($computer->isOnline())
                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-full px-2 py-0.5 border bg-green-50 text-green-700 border-green-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-green-500" aria-hidden="true"></span> Online
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-full px-2 py-0.5 border bg-slate-100 text-slate-600 border-slate-200">
                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400" aria-hidden="true"></span> Offline
                                        </span>
                                    @endif
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        {{ $computer->last_seen_at?->diffForHumans() ?? 'never seen' }}
                                    </p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-1">
                                    @if ($computer->trashed())
                                        @can('restore', $computer)
                                            <x-icon-button icon="restore" label="Restore" wire:click="restore({{ $computer->id }})" />
                                        @endcan
                                        @can('forceDelete', $computer)
                                            <x-icon-button icon="delete" variant="danger" label="Delete permanently"
                                                           wire:click="forceDelete({{ $computer->id }})"
                                                           wire:confirm="Permanently delete “{{ $computer->hostname }}” and all its history? Only possible once its agent is uninstalled — this cannot be undone." />
                                        @endcan
                                    @else
                                        @can('update', $computer)
                                            <x-icon-button icon="reassign" label="Reassign project" :href="route('computers.edit', $computer)" />
                                        @endcan
                                        @can('delete', $computer)
                                            <x-icon-button icon="delete" variant="danger" label="Retire"
                                                           wire:click="delete({{ $computer->id }})"
                                                           wire:confirm="Retire “{{ $computer->hostname }}”? It moves to the retired list (Show deleted) with its history kept. If its agent reports again it is revived automatically. To remove it for good: uninstall the agent, then delete permanently from the retired list." />
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No computers found. Agents appear here after they register (Phase 7).</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $computers->links() }}
        </div>
    </div>
</div>
