<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">Reassign {{ $computer->hostname }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <p class="text-sm text-slate-600">
                    Currently in <span class="font-semibold">{{ $computer->project->client->company_name }}
                    / {{ $computer->project->name }}</span>. Inventory fields are owned by the agent
                    and refresh on its next report.
                </p>

                <div>
                    <x-label for="project_id" value="Project" />
                    <select id="project_id" wire:model="project_id"
                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->client->company_name }} / {{ $project->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error for="project_id" class="mt-1" />
                </div>

                <div class="flex justify-end gap-3 border-t pt-4">
                    <a href="{{ route('computers.show', $computer) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Cancel
                    </a>
                    <x-button>Reassign</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
