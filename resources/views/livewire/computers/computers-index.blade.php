<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Computers') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search hostname, serial, IP, MAC…" aria-label="Search computers"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-80">
                <select wire:model.live="clientId" aria-label="Filter by client"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All clients</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="projectId" aria-label="Filter by project"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All projects</option>
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
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="showTrashed" class="rounded border-slate-300">
                    Show deleted
                </label>
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Hostname</th>
                            <th class="pd-th">Client / Project</th>
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
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm">{{ $computer->agent_version ?? '—' }}</td>
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
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-2">
                                    @if ($computer->trashed())
                                        @can('restore', $computer)
                                            <button wire:click="restore({{ $computer->id }})"
                                                    class="pd-action">Restore</button>
                                        @endcan
                                    @else
                                        @can('update', $computer)
                                            <a href="{{ route('computers.edit', $computer) }}"
                                               class="pd-action">Reassign</a>
                                        @endcan
                                        @can('delete', $computer)
                                            <button wire:click="delete({{ $computer->id }})"
                                                    wire:confirm="Delete “{{ $computer->hostname }}”? If its agent reports again it will be revived automatically."
                                                    class="pd-action-danger">Delete</button>
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
