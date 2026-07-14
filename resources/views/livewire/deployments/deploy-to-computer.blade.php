<div class="pd-card !overflow-visible p-6">
    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Deploy software</h3>

    @if (session('status'))
        <div class="mb-3 rounded-md bg-green-50 border border-green-200 p-2 text-sm text-green-700" role="status">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="queue" class="grid grid-cols-1 md:grid-cols-4 gap-2 items-start">
        <div class="md:col-span-2">
            <x-searchable-select wire:model="package_id" placeholder="— select package —"
                :options="$packages->map(fn ($p) => ['value' => $p->id, 'label' => $p->name . ' (' . $p->installer_type->label() . ')'])->values()->all()" />
            <x-input-error for="package_id" class="mt-1" />
        </div>
        <div>
            <select wire:model="action" aria-label="Action"
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
            <x-button type="submit">Queue</x-button>
        </div>
    </form>
</div>
