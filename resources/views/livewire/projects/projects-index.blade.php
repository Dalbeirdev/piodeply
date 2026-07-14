<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Projects') }}</h2>
            @can('create', \App\Models\Project::class)
                <a href="{{ route('projects.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
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
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-72">
                <select wire:model.live="clientId" aria-label="Filter by client"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">All clients</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="status" aria-label="Filter by status"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $statusOption)
                        <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="showTrashed" class="rounded border-gray-300">
                    Show deleted
                </label>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">API key</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Download URL</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($projects as $project)
                            <tr @class(['opacity-60' => $project->trashed()])>
                                <td class="px-6 py-3">
                                    <span class="font-medium text-gray-900">{{ $project->name }}</span>
                                    @if ($project->trashed())
                                        <span class="ml-1 text-xs rounded-full bg-red-50 text-red-600 border border-red-200 px-2 py-0.5">deleted</span>
                                    @endif
                                    @if ($project->description)
                                        <p class="text-xs text-gray-500 max-w-xs truncate">{{ $project->description }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600">{{ $project->client->company_name }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <code class="text-xs text-gray-600 font-mono">{{ $project->api_key_prefix }}…</code>
                                    @if ($project->api_key_rotated_at)
                                        <p class="text-xs text-gray-400">rotated {{ $project->api_key_rotated_at->diffForHumans() }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <code class="text-xs text-gray-600 font-mono select-all">{{ $project->downloadUrl() }}</code>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span @class([
                                        'text-xs font-semibold rounded-full px-2 py-0.5 border',
                                        'bg-green-50 text-green-700 border-green-200' => $project->status === \App\Enums\ProjectStatus::Active,
                                        'bg-gray-100 text-gray-600 border-gray-200' => $project->status === \App\Enums\ProjectStatus::Archived,
                                    ])>{{ $project->status->label() }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-2">
                                    @if ($project->trashed())
                                        @can('restore', $project)
                                            <button wire:click="restore({{ $project->id }})"
                                                    class="font-semibold text-indigo-600 hover:underline">Restore</button>
                                        @endcan
                                    @else
                                        @can('rotateApiKey', $project)
                                            <button wire:click="rotateKey({{ $project->id }})"
                                                    wire:confirm="Rotate the API key for “{{ $project->name }}”? Every agent using the old key stops authenticating immediately."
                                                    class="font-semibold text-amber-600 hover:underline">Rotate key</button>
                                        @endcan
                                        @can('update', $project)
                                            <a href="{{ route('projects.edit', $project) }}"
                                               class="font-semibold text-indigo-600 hover:underline">Edit</a>
                                        @endcan
                                        @can('delete', $project)
                                            <button wire:click="delete({{ $project->id }})"
                                                    wire:confirm="Delete project “{{ $project->name }}”? It can be restored later."
                                                    class="font-semibold text-red-600 hover:underline">Delete</button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No projects found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $projects->links() }}
        </div>
    </div>
</div>
