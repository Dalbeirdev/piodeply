<div class="pd-card !overflow-visible p-6">
    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Deploy software</h3>

    @if (session('status'))
        <div class="mb-3 rounded-md bg-green-50 border border-green-200 p-2 text-sm text-green-700" role="status">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="queue" class="grid grid-cols-1 md:grid-cols-4 gap-2 items-start">
        <div class="md:col-span-2">
            <x-searchable-select wire:model.live="package_id" placeholder="— select package —"
                :options="$packages->map(fn ($p) => ['value' => $p->id, 'label' => $p->name . ' (' . $p->installer_type->label() . ')'])->values()->all()" />
            <x-input-error for="package_id" class="mt-1" />
        </div>
        <div>
            <select wire:model.live="action" aria-label="Action"
                    class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                @foreach ($actions as $a)
                    <option value="{{ $a->value }}">{{ $a->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <select wire:model="priority" aria-label="Priority"
                    class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm" title="1 = highest">
                @foreach (range(1, 10) as $p)
                    <option value="{{ $p }}">P{{ $p }}</option>
                @endforeach
            </select>
            <x-button type="submit" :disabled="! $canQueue"
                      class="whitespace-nowrap {{ $canQueue ? '' : '!bg-slate-300 !text-slate-600 cursor-not-allowed hover:!bg-slate-300' }}">
                {{ $canQueue ? '' : '✓ ' }}{{ $label }}
            </x-button>
        </div>

        {{-- What the machine reports right now, so the choice is made with
             the facts rather than discovered after queueing. --}}
        @if ($package)
            <div class="md:col-span-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                @if ($state['present'])
                    <span class="inline-flex items-center gap-1.5 text-slate-600">
                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                        Installed on {{ $computer->hostname }}
                        @if ($state['version'])
                            <span class="font-mono text-slate-500">{{ $state['version'] }}</span>
                        @elseif (! $versionKnown)
                            <span class="text-slate-400">(version not reported for {{ $package->installer_type->label() }} packages)</span>
                        @endif
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-slate-500">
                        <span class="w-1.5 h-1.5 rounded-full bg-slate-300"></span>
                        Not installed on {{ $computer->hostname }}
                    </span>
                @endif

                @if ($versionKnown)
                    <label class="inline-flex items-center gap-1.5 text-slate-500">
                        Pin version
                        @if ($offeredVersions !== null)
                            {{-- We know what the source publishes, so only offer
                                 versions that will actually install. --}}
                            <select wire:model.live="target_version" aria-label="Pin version"
                                    class="py-0.5 text-xs font-mono border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded shadow-sm">
                                <option value="">latest</option>
                                @foreach ($offeredVersions as $offered)
                                    <option value="{{ $offered }}">{{ $offered }}</option>
                                @endforeach
                            </select>
                        @else
                            {{-- Could not read the source: a free-text box is
                                 honest, an empty dropdown would claim there are
                                 no versions. --}}
                            <input type="text" wire:model.live.debounce.400ms="target_version"
                                   placeholder="latest" aria-label="Pin version"
                                   class="w-24 py-0.5 text-xs font-mono border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded shadow-sm">
                        @endif
                    </label>
                    @if ($offeredVersions !== null && count($offeredVersions) === 1)
                        <span class="text-amber-600" title="Rolling back needs an older version to still be published">
                            Only one version is published — this package cannot be rolled back.
                        </span>
                    @endif
                @endif

                @if ($rollbackTo)
                    {{-- One-click rollback to the version this machine ran
                         before its most recent change (from job history). --}}
                    <button type="button" wire:click="rollbackToPrevious"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold rounded-md px-2.5 py-1 border bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100 transition-colors"
                            title="Queue a rollback to the last version this machine ran">
                        <span aria-hidden="true">&#8617;</span> Roll back to {{ $rollbackTo }}
                    </button>
                @endif

                @if ($satisfied)
                    <label class="inline-flex items-center gap-1.5 text-slate-500 select-none">
                        <input type="checkbox" wire:model.live="force"
                               class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        Force anyway (repairs a broken install)
                    </label>
                @endif
            </div>
        @endif
    </form>
</div>
