<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Projects') }}</h2>
            @can('create', \App\Models\Project::class)
                <a href="{{ route('projects.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                    + New Project
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if (session('new_api_key'))
                <div class="rounded-md bg-yellow-50 border border-yellow-300 p-4 text-sm text-yellow-800" role="alert">
                    <p class="font-semibold">API key for “{{ session('new_api_key_project') }}” — copy it now, it will not be shown again:</p>
                    <code class="mt-1 block select-all break-all bg-white border border-yellow-200 rounded px-2 py-1 font-mono">{{ session('new_api_key') }}</code>
                </div>
            @endif

            @if ($revealedKey)
                <div class="rounded-md bg-yellow-50 border border-yellow-300 p-4 text-sm text-yellow-800" role="alert">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="font-semibold">New API key (rotation) — copy it now, it will not be shown again. The old key is dead.</p>
                            <code class="mt-1 block select-all break-all bg-white border border-yellow-200 rounded px-2 py-1 font-mono">{{ $revealedKey }}</code>
                        </div>
                        <button wire:click="dismissKey" class="text-yellow-700 font-semibold hover:underline">Dismiss</button>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search project or client…" aria-label="Search projects"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-72">
                @unless ($isTenant ?? false)
<select wire:model.live="clientId" aria-label="Filter by client"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All clients</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                    @endforeach
                </select>
@endunless
                <select wire:model.live="status" aria-label="Filter by status"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                    @endforeach
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
                            <th class="pd-th">Project</th>
                            <th class="pd-th">Client</th>
                            <th class="pd-th">API key</th>
                            <th class="pd-th">Download URL</th>
                            <th class="pd-th">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($projects as $project)
                            <tr @class(['opacity-60' => $project->trashed()])>
                                <td class="px-6 py-3">
                                    <span class="font-medium text-slate-900">{{ $project->name }}</span>
                                    @if ($project->trashed())
                                        <span class="ml-1 text-xs rounded-full bg-red-50 text-red-600 border border-red-200 px-2 py-0.5">deleted</span>
                                    @endif
                                    @if ($project->description)
                                        <p class="text-xs text-slate-500 max-w-xs truncate">{{ $project->description }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">{{ $project->client->company_name }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <code class="text-xs text-slate-600 font-mono">{{ $project->api_key_prefix }}…</code>
                                    @if ($project->api_key_rotated_at)
                                        <p class="text-xs text-slate-400">rotated {{ $project->api_key_rotated_at->diffForHumans() }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <code class="text-xs text-slate-600 font-mono select-all">{{ $project->downloadUrl() }}</code>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span @class([
                                        'text-xs font-semibold rounded-full px-2 py-0.5 border',
                                        'bg-green-50 text-green-700 border-green-200' => $project->status === \App\Enums\ProjectStatus::Active,
                                        'bg-slate-100 text-slate-600 border-slate-200' => $project->status === \App\Enums\ProjectStatus::Archived,
                                    ])>{{ $project->status->label() }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-1">
                                    @if ($project->trashed())
                                        @can('restore', $project)
                                            <x-icon-button icon="restore" label="Restore" wire:click="restore({{ $project->id }})" />
                                        @endcan
                                    @else
                                        @can('rotateApiKey', $project)
                                            <x-icon-button icon="key" variant="amber" label="Rotate API key"
                                                           wire:click="rotateKey({{ $project->id }})"
                                                           wire:confirm="Rotate the API key for “{{ $project->name }}”? Every agent using the old key stops authenticating immediately." />
                                        @endcan
                                        <x-icon-button icon="download" label="Enrol machines"
                                                       :href="route('projects.enrollment', $project)" />
                                        @can('update', $project)
                                            <x-icon-button icon="edit" label="Edit" :href="route('projects.edit', $project)" />
                                        @endcan
                                        @can('delete', $project)
                                            <x-icon-button icon="delete" variant="danger" label="Delete"
                                                           wire:click="delete({{ $project->id }})"
                                                           wire:confirm="Delete project “{{ $project->name }}”? It can be restored later." />
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No projects found.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $projects->links() }}
        </div>
    </div>
</div>
