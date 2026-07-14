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
                    <select id="action" wire:model.live="action"
                            class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        @foreach ($actions as $actionOption)
                            <option value="{{ $actionOption->value }}">{{ $actionOption->description() }}</option>
                        @endforeach
                    </select>
                    <x-input-error for="action" class="mt-1" />
                </div>

                @if (! in_array($action, ['uninstall', 'block'], true))
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-label for="version_mode" value="Version" />
                            <select id="version_mode" wire:model.live="version_mode"
                                    class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                @foreach ($versionModes as $versionModeOption)
                                    <option value="{{ $versionModeOption->value }}">{{ $versionModeOption->label() }}</option>
                                @endforeach
                            </select>
                            <x-input-error for="version_mode" class="mt-1" />
                        </div>
                        @if ($version_mode !== 'latest' || $action === 'force_update')
                            <div>
                                <x-label for="desired_version" value="Desired version" />
                                <x-input id="desired_version" type="text" placeholder="e.g. 24.09"
                                         class="mt-1 block w-full" wire:model="desired_version" />
                                <x-input-error for="desired_version" class="mt-1" />
                            </div>
                        @endif
                    </div>
                    @if ($version_mode === 'exact')
                        <p class="text-xs text-slate-500 -mt-3">Machines on any other version are moved to exactly this version — including downgrades.</p>
                    @elseif ($version_mode === 'minimum')
                        <p class="text-xs text-slate-500 -mt-3">Machines below this version are updated; machines at or above it are left alone.</p>
                    @elseif ($version_mode === 'maximum')
                        <p class="text-xs text-slate-500 -mt-3">Freeze: machines above this version are downgraded back to it; updates never go past it.</p>
                    @endif
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-label for="mode" value="Mode" />
                        <select id="mode" wire:model="mode"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            @foreach ($modes as $modeOption)
                                <option value="{{ $modeOption->value }}">{{ $modeOption->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="mode" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="priority" value="Priority" />
                        <select id="priority" wire:model="priority"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            @foreach ($priorities as $priorityLabel => $priorityValue)
                                <option value="{{ $priorityValue }}">{{ $priorityLabel }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="priority" class="mt-1" />
                    </div>
                </div>

                <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                    <strong>Enforce</strong> queues jobs for machines out of desired state — automatically as agents
                    report in. <strong>Audit only</strong> shows compliance on the policy page but never changes a
                    machine. Version pinning requires a winget package.
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
