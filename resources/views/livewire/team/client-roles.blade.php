<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-900 leading-tight">Custom roles</h2>
            {{-- Header slot = outside the Livewire DOM, so dispatch, never wire:click. --}}
            <button type="button" onclick="Livewire.dispatch('client-roles-new')"
                    class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                New role
            </button>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">{{ session('error') }}</div>
            @endif

            <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                A custom role decides <b>what</b> a team member may do — install, update, uninstall —
                and <b>on which machines</b>. Assign it when adding a member on the
                <a href="{{ route('team.index') }}" class="pd-link">Team page</a>. Members with a custom
                role see only the machines it covers.
            </div>

            @if ($showForm)
                <div class="pd-card p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-slate-800">{{ $editingId ? 'Edit role' : 'New role' }}</h3>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-600">Role name</label>
                            <input type="text" wire:model="name" placeholder="e.g. Updater — front desk"
                                   class="mt-1 block w-full text-sm border-slate-300 rounded-md">
                            @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600">Description (optional)</label>
                            <input type="text" wire:model="description"
                                   class="mt-1 block w-full text-sm border-slate-300 rounded-md">
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-medium text-slate-600 mb-2">Allowed actions</p>
                        <div class="flex flex-wrap gap-5">
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model="can_install" class="rounded border-slate-300"> Install software
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model="can_update" class="rounded border-slate-300"> Update software
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="checkbox" wire:model="can_uninstall" class="rounded border-slate-300"> Uninstall software
                            </label>
                        </div>
                        @error('can_update')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <p class="text-xs font-medium text-slate-600 mb-2">Where it applies</p>
                        <div class="flex flex-wrap gap-5">
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="radio" wire:model.live="scope" value="all" class="border-slate-300"> All machines
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="radio" wire:model.live="scope" value="sites" class="border-slate-300"> Specific {{ project_terms_lower() }}
                            </label>
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input type="radio" wire:model.live="scope" value="computers" class="border-slate-300"> Specific machines
                            </label>
                        </div>

                        @if ($scope === 'sites')
                            <p class="text-xs text-slate-500 mt-2">
                                Machines that enrol into these {{ project_terms_lower() }} later are covered automatically.
                            </p>
                            <div class="mt-2 max-h-56 overflow-y-auto border border-slate-200 rounded-md p-3 grid sm:grid-cols-2 gap-1.5">
                                @forelse ($sites as $site)
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" wire:model="projectIds" value="{{ $site->id }}"
                                               class="rounded border-slate-300">
                                        {{ $site->name }}
                                    </label>
                                @empty
                                    <p class="text-sm text-slate-500">No {{ project_terms_lower() }} yet.</p>
                                @endforelse
                            </div>
                            @error('projectIds')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        @elseif ($scope === 'computers')
                            <div class="mt-2 max-h-56 overflow-y-auto border border-slate-200 rounded-md p-3 grid sm:grid-cols-2 gap-1.5">
                                @forelse ($machines as $machine)
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" wire:model="computerIds" value="{{ $machine->id }}"
                                               class="rounded border-slate-300">
                                        {{ $machine->hostname }}
                                    </label>
                                @empty
                                    <p class="text-sm text-slate-500">No machines enrolled yet.</p>
                                @endforelse
                            </div>
                            @error('computerIds')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        @endif
                    </div>

                    <div class="flex gap-2">
                        <button type="button" wire:click="save"
                                class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                            Save role
                        </button>
                        <button type="button" wire:click="$set('showForm', false)" class="text-sm pd-action">Cancel</button>
                    </div>
                </div>
            @endif

            <div class="pd-card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-400 uppercase tracking-wide">
                            <th class="px-6 py-3">Role</th>
                            <th class="px-6 py-3">Allows</th>
                            <th class="px-6 py-3">Members</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($roles as $role)
                            <tr>
                                <td class="px-6 py-3">
                                    <span class="font-medium text-slate-800">{{ $role->name }}</span>
                                    @if ($role->description)
                                        <p class="text-xs text-slate-500">{{ $role->description }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-slate-600">{{ $role->summary() }}</td>
                                <td class="px-6 py-3 text-slate-600">{{ $role->users_count }}</td>
                                <td class="px-6 py-3 text-right space-x-1">
                                    <x-icon-button icon="edit" label="Edit" wire:click="edit({{ $role->id }})" />
                                    <x-icon-button icon="delete" variant="danger" label="Delete"
                                                   wire:click="delete({{ $role->id }})"
                                                   wire:confirm="Delete the role “{{ $role->name }}”?" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500">
                                No custom roles yet — create one to give a team member precise,
                                machine-level permissions.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
