<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ $project ? 'Edit ' . $project->name : 'New Project' }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <div>
                    <x-label for="client_id" value="Client" />
                    <select id="client_id" wire:model="client_id"
                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        <option value="">— select client —</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->company_name }}</option>
                        @endforeach
                    </select>
                    <x-input-error for="client_id" class="mt-1" />
                </div>

                <div>
                    <x-label for="name" value="Project name" />
                    <x-input id="name" type="text" class="mt-1 block w-full" wire:model="name" required />
                    <x-input-error for="name" class="mt-1" />
                </div>

                <div>
                    <x-label for="description" value="Description" />
                    <textarea id="description" rows="3" wire:model="description"
                              class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm"></textarea>
                    <x-input-error for="description" class="mt-1" />
                </div>

                <div>
                    <x-label for="status" value="Status" />
                    <select id="status" wire:model="status"
                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        @foreach ($statuses as $statusOption)
                            <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                        @endforeach
                    </select>
                    <x-input-error for="status" class="mt-1" />
                </div>

                @if ($project)
                    <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                        <p><span class="font-semibold">API key:</span>
                            <code class="font-mono">{{ $project->api_key_prefix }}…</code>
                            (rotate from the projects list)</p>
                        <p class="mt-1"><span class="font-semibold">Agent download URL:</span>
                            <code class="font-mono select-all">{{ $project->downloadUrl() }}</code></p>
                    </div>
                @else
                    <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                        An API key and download URL are generated when the project is created.
                        The key is shown <strong>once</strong> — have somewhere safe ready to store it.
                    </div>
                @endif

                <div class="flex justify-end gap-3 border-t pt-4">
                    <a href="{{ route('projects.index') }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Cancel
                    </a>
                    <x-button>{{ $project ? 'Save changes' : 'Create project' }}</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
