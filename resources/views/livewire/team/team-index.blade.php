<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-900 leading-tight">Team</h2>
            <button type="button" wire:click="$toggle('showCreate')"
                    class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                Add team member
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

            @if ($showCreate)
                <div class="pd-card p-6 space-y-3">
                    <h3 class="text-sm font-semibold text-slate-800">New team member</h3>
                    <p class="text-xs text-slate-500">
                        Everyone you add here belongs to your organisation and can only ever see your
                        projects, machines and data. Pick the level of access below — they sign in at this
                        same site with the email and password you set.
                    </p>
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-600">Name</label>
                            <input type="text" wire:model="newName" class="mt-1 block w-full text-sm border-slate-300 rounded-md">
                            @error('newName')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600">Email</label>
                            <input type="email" wire:model="newEmail" class="mt-1 block w-full text-sm border-slate-300 rounded-md">
                            @error('newEmail')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600">Password</label>
                            <input type="password" wire:model="newPassword" class="mt-1 block w-full text-sm border-slate-300 rounded-md">
                            @error('newPassword')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-600">Role</label>
                            <select wire:model.live="newRole" class="mt-1 block w-full text-sm border-slate-300 rounded-md">
                                @foreach ($grantable as $role)
                                    <option value="{{ $role }}">{{ $role === 'Client Owner' ? 'Administrator' : $role }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-slate-500 mt-1">{{ $roleHelp[$newRole] ?? '' }}</p>
                            @error('newRole')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="create"
                                class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                            Create account
                        </button>
                        <button type="button" wire:click="$set('showCreate', false)" class="text-sm pd-action">Cancel</button>
                    </div>
                </div>
            @endif

            <div class="pd-card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-400 uppercase tracking-wide">
                            <th class="px-6 py-3">Name</th>
                            <th class="px-6 py-3">Email</th>
                            <th class="px-6 py-3">Role</th>
                            <th class="px-6 py-3">Projects</th>
                            <th class="px-6 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($members as $member)
                            <tr>
                                <td class="px-6 py-3 font-medium text-slate-800">
                                    {{ $member->name }}
                                    @if ($member->id === auth()->id())<span class="text-xs text-slate-400">(you)</span>@endif
                                </td>
                                <td class="px-6 py-3">{{ $member->email }}</td>
                                <td class="px-6 py-3">
                                    @php $memberRole = $member->getRoleNames()->first(); @endphp
                                    <span class="pd-badge pd-badge-slate"
                                          title="{{ $roleHelp[$memberRole] ?? '' }}">{{ $memberRole === 'Client Owner' ? 'Administrator' : ($memberRole ?? '—') }}</span>
                                </td>
                                <td class="px-6 py-3">
                                    @php $isOwner = $member->isClientOwner(); @endphp
                                    @if ($isOwner)
                                        <span class="text-xs text-slate-400">All projects (owner)</span>
                                    @else
                                        <div class="flex flex-wrap items-center gap-1">
                                            @forelse ($member->assignedProjects as $project)
                                                <span class="text-xs bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5">
                                                    {{ $project->name }}
                                                    <button type="button" wire:click="unassignFromProject({{ $member->id }}, {{ $project->id }})"
                                                            class="ml-0.5 text-slate-400 hover:text-rose-600" title="Remove assignment">×</button>
                                                </span>
                                            @empty
                                                <span class="text-xs text-slate-400" title="Assign a project to limit this person to it">All projects</span>
                                            @endforelse
                                            @if ($projects->count() > $member->assignedProjects->count())
                                                <select wire:model="assignProject.{{ $member->id }}" class="text-xs border-slate-300 rounded-md py-0.5">
                                                    <option value="">Limit to…</option>
                                                    @foreach ($projects as $project)
                                                        @if (! $member->assignedProjects->contains($project->id))
                                                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                                                        @endif
                                                    @endforeach
                                                </select>
                                                <button type="button" wire:click="assignToProject({{ $member->id }})" class="text-xs pd-action">Assign</button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right">
                                    @if ($member->id !== auth()->id() && ! $member->isClientOwner())
                                        <button type="button" wire:click="remove({{ $member->id }})"
                                                wire:confirm="Remove {{ $member->name }}? They will no longer be able to sign in."
                                                class="text-sm text-rose-600 hover:text-rose-700">Remove</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
