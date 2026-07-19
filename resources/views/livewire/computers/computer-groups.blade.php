<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Device Groups') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                A group is a hand-picked set of machines that can cut across clients and projects —
                finance workstations, kiosks, a pilot ring. Browser policies can target a group directly,
                and group scope beats project and client scope when policies overlap.
            </div>

            @if ($canManage)
                <div class="pd-card p-5">
                    <h3 class="font-semibold text-slate-800">New group</h3>
                    <form wire:submit="create" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 items-start">
                        <div>
                            <input type="text" wire:model="newName" placeholder="Group name"
                                   class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                            <x-input-error for="newName" class="mt-1" />
                        </div>
                        <div>
                            <input type="text" wire:model="newDescription" placeholder="Description (optional)"
                                   class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                            <x-input-error for="newDescription" class="mt-1" />
                        </div>
                        <div><x-button type="submit">Create group</x-button></div>
                    </form>
                </div>
            @endif

            <div class="space-y-3">
                @forelse ($groups as $group)
                    <div class="pd-card p-5" wire:key="group-{{ $group->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-800">{{ $group->name }}</h3>
                                @if ($group->description)
                                    <p class="text-sm text-slate-500 mt-0.5">{{ $group->description }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <span class="text-xs text-slate-400">{{ $group->computers_count }} {{ Str::plural('machine', $group->computers_count) }}</span>
                                @if ($canManage)
                                    <button type="button" wire:click="delete({{ $group->id }})"
                                            wire:confirm="Delete “{{ $group->name }}”? Machines are unaffected; policies scoped to this group stop targeting anything."
                                            class="text-xs font-semibold text-red-600 hover:text-red-700">Delete</button>
                                    <button type="button" wire:click="manage({{ $group->id }})"
                                            class="inline-flex items-center px-3 py-1.5 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                                        {{ $managingId === $group->id ? 'Close' : 'Manage' }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if ($managingId === $group->id)
                            <div class="mt-4 border-t border-slate-100 pt-4 space-y-3">
                                <form wire:submit="addMember" class="flex items-center gap-2">
                                    <select wire:model="addComputerId" aria-label="Add computer"
                                            class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm w-72">
                                        <option value="">— add a computer —</option>
                                        @foreach ($available as $computer)
                                            <option value="{{ $computer->id }}">{{ $computer->hostname }}</option>
                                        @endforeach
                                    </select>
                                    <x-button type="submit">Add</x-button>
                                    <x-input-error for="addComputerId" />
                                </form>

                                @if ($group->computers->isEmpty())
                                    <p class="text-sm text-slate-400">No machines yet.</p>
                                @else
                                    <ul class="divide-y divide-slate-100">
                                        @foreach ($group->computers as $member)
                                            <li class="py-1.5 flex items-center justify-between gap-2 text-sm" wire:key="member-{{ $group->id }}-{{ $member->id }}">
                                                <span>
                                                    <a href="{{ route('computers.show', $member) }}" class="pd-link font-medium">{{ $member->hostname }}</a>
                                                    <span class="text-xs text-slate-400 ml-1">{{ $member->project->client->company_name }} / {{ $member->project->name }}</span>
                                                </span>
                                                <button type="button" wire:click="removeMember({{ $group->id }}, {{ $member->id }})"
                                                        class="text-xs font-semibold text-slate-400 hover:text-red-600">Remove</button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="pd-card p-8 text-center text-slate-500">No groups yet — create the first one above.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
