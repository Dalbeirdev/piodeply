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
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">
                    {{ session('error') }}
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

            {{-- Cards, not a wide table: the API key and the long download URL
                 used to force a horizontal scrollbar. A card fits any width,
                 shows every field in full, and lets "Enrol machines" be a real
                 highlighted button instead of a raw URL to copy by hand. --}}
            <div class="space-y-3">
                @forelse ($projects as $project)
                    <div @class(['pd-card p-5', 'opacity-60' => $project->trashed()])>
                        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                            {{-- Identity --}}
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="font-semibold text-slate-900 text-base leading-tight">{{ $project->name }}</h3>
                                    <span @class([
                                        'text-xs font-semibold rounded-full px-2 py-0.5 border',
                                        'bg-green-50 text-green-700 border-green-200' => $project->status === \App\Enums\ProjectStatus::Active,
                                        'bg-slate-100 text-slate-600 border-slate-200' => $project->status === \App\Enums\ProjectStatus::Archived,
                                    ])>{{ $project->status->label() }}</span>
                                    @if ($project->trashed())
                                        <span class="text-xs rounded-full bg-red-50 text-red-600 border border-red-200 px-2 py-0.5">deleted</span>
                                    @endif
                                </div>
                                <p class="text-sm text-slate-500 mt-0.5">{{ $project->client->company_name }}</p>
                                @if ($project->description)
                                    <p class="text-xs text-slate-500 mt-1 max-w-2xl">{{ $project->description }}</p>
                                @endif
                            </div>

                            {{-- Actions: Enrol is the highlighted primary. --}}
                            <div class="flex items-center gap-1.5 shrink-0">
                                @if ($project->trashed())
                                    @can('restore', $project)
                                        <x-icon-button icon="restore" label="Restore" wire:click="restore({{ $project->id }})" />
                                    @endcan
                                @else
                                    <a href="{{ route('projects.enrollment', $project) }}"
                                       class="inline-flex items-center gap-2 px-4 py-2 bg-teal-700 text-white rounded-lg font-semibold text-sm shadow-sm hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-1">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                        Enrol machines
                                    </a>
                                    @can('rotateApiKey', $project)
                                        <x-icon-button icon="key" variant="amber" label="Rotate API key"
                                                       wire:click="rotateKey({{ $project->id }})"
                                                       wire:confirm="Rotate the API key for “{{ $project->name }}”? Every agent using the old key stops authenticating immediately." />
                                    @endcan
                                    @can('update', $project)
                                        <x-icon-button icon="edit" label="Edit" :href="route('projects.edit', $project)" />
                                    @endcan
                                    @can('delete', $project)
                                        <x-icon-button icon="delete" variant="danger" label="Delete"
                                                       wire:click="delete({{ $project->id }})"
                                                       wire:confirm="Delete project “{{ $project->name }}”? Only possible when it has no machines — active or retired. It can be restored later." />
                                    @endcan
                                @endif
                            </div>
                        </div>

                        {{-- Credentials, shown in full, wrapping so nothing scrolls. --}}
                        @unless ($project->trashed())
                            <div class="mt-4 pt-4 border-t border-slate-100 grid gap-4 sm:grid-cols-2"
                                 x-data="{ copied: false, copy(v) { navigator.clipboard.writeText(v); this.copied = true; setTimeout(() => this.copied = false, 1500); } }">
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">API key</p>
                                    <code class="text-sm text-slate-700 font-mono">{{ $project->api_key_prefix }}…</code>
                                    @if ($project->api_key_rotated_at)
                                        <span class="text-xs text-slate-400 ml-1">rotated {{ $project->api_key_rotated_at->diffForHumans() }}</span>
                                    @endif
                                    <p class="text-[11px] text-slate-400 mt-0.5">Full key is shown once on creation or rotation — never stored in readable form.</p>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">Download link</p>
                                    <div class="flex items-start gap-2">
                                        <code class="text-xs text-slate-600 font-mono break-all select-all min-w-0">{{ $project->downloadUrl() }}</code>
                                        <button type="button" x-on:click="copy(@js($project->downloadUrl()))"
                                                class="shrink-0 text-xs font-semibold text-teal-700 hover:text-teal-900"
                                                x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                                    </div>
                                </div>
                            </div>
                        @endunless
                    </div>
                @empty
                    <div class="pd-card p-10 text-center text-slate-500">No projects found.</div>
                @endforelse
            </div>

            {{ $projects->links() }}
        </div>
    </div>
</div>
