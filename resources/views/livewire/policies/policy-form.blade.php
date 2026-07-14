<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ $policy ? 'Edit policy' : 'New Policy' }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <div>
                    <x-label for="project_id" value="Project" />
                    <select id="project_id" wire:model="project_id"
                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        <option value="">— select project —</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error for="project_id" class="mt-1" />
                </div>

                <div>
                    <x-label for="package_id" value="Package" />
                    <select id="package_id" wire:model="package_id"
                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        <option value="">— select package —</option>
                        @foreach ($packages as $package)
                            <option value="{{ $package->id }}">{{ $package->name }} ({{ $package->installer_type->label() }})</option>
                        @endforeach
                    </select>
                    <x-input-error for="package_id" class="mt-1" />
                </div>

                <div>
                    <x-label for="action" value="Rule" />
                    <select id="action" wire:model="action"
                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        <option value="install">Install — put it on machines that don't have it</option>
                        <option value="update">Auto update — keep it current on machines that have it</option>
                        <option value="uninstall">Remove — uninstall it wherever it's found</option>
                    </select>
                    <x-input-error for="action" class="mt-1" />
                </div>

                <div>
                    <x-label for="priority" value="Priority (1 = highest, 10 = lowest)" />
                    <x-input id="priority" type="number" min="1" max="10" class="mt-1 block w-24" wire:model="priority" />
                    <x-input-error for="priority" class="mt-1" />
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <x-checkbox wire:model="is_active" />
                    Active — enforce automatically as agents report in
                </label>

                <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                    Jobs are only queued for machines that actually need the action — machines already
                    compliant, or with a job in flight, are skipped.
                </div>

                <div class="flex justify-end gap-3 border-t pt-4">
                    <a href="{{ route('policies.index') }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Cancel
                    </a>
                    <x-button>{{ $policy ? 'Save changes' : 'Create policy' }}</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
