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
                        <strong>Technician</strong> can deploy software and manage machines.
                        <strong>Viewer</strong> can see everything but change nothing.
                        They sign in at this same site with the email and password you set here.
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
                            <select wire:model="newRole" class="mt-1 block w-full text-sm border-slate-300 rounded-md">
                                @foreach ($grantable as $role)
                                    <option value="{{ $role }}">{{ $role }}</option>
                                @endforeach
                            </select>
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
                                    <span class="pd-badge pd-badge-slate">{{ $member->getRoleNames()->first() ?? '—' }}</span>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    @if ($member->id !== auth()->id() && ! $member->hasRole(\App\Enums\Role::Manager->value))
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
