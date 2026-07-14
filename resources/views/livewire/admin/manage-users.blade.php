<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Users') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex items-center justify-between">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search name or email…" aria-label="Search users"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-80">
                <div class="flex items-center gap-3">
                    <span x-data="{ shown: false }"
                          x-on:role-updated.window="shown = true; setTimeout(() => shown = false, 2000)"
                          x-show="shown" x-transition
                          class="text-sm text-green-600 font-medium" style="display:none">
                        Role updated.
                    </span>
                    @can('create', \App\Models\User::class)
                        <button type="button" wire:click="$toggle('showCreate')"
                                class="inline-flex items-center px-4 py-2 bg-teal-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-500">
                            Add user
                        </button>
                    @endcan
                </div>
            </div>

            @if ($showCreate)
                @can('create', \App\Models\User::class)
                    <form wire:submit="createUser" class="pd-card p-6 space-y-4">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">New user</h3>
                        <p class="text-xs text-slate-500">
                            Public self-registration is disabled — accounts are created here. The account is
                            created verified; share the password securely and ask them to change it after
                            first sign-in. Bind Client-role users to their client from the table below.
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                            <div>
                                <x-label for="newName" value="Name" />
                                <x-input id="newName" type="text" class="mt-1 block w-full" wire:model="newName" />
                                <x-input-error for="newName" class="mt-1" />
                            </div>
                            <div>
                                <x-label for="newEmail" value="Email" />
                                <x-input id="newEmail" type="email" class="mt-1 block w-full" wire:model="newEmail" />
                                <x-input-error for="newEmail" class="mt-1" />
                            </div>
                            <div>
                                <x-label for="newPassword" value="Password" />
                                <x-input id="newPassword" type="text" class="mt-1 block w-full" wire:model="newPassword"
                                         placeholder="10+ chars, letters + numbers" autocomplete="off" />
                                <x-input-error for="newPassword" class="mt-1" />
                            </div>
                            <div>
                                <x-label for="newRole" value="Role" />
                                <select id="newRole" wire:model="newRole"
                                        class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                    <option value="">— select role —</option>
                                    @foreach ($roles as $role)
                                        @continue($role === \App\Enums\Role::SuperAdmin->value)
                                        <option value="{{ $role }}">{{ $role }}</option>
                                    @endforeach
                                </select>
                                <x-input-error for="newRole" class="mt-1" />
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" wire:click="$set('showCreate', false)"
                                    class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                                Cancel
                            </button>
                            <x-button>Create user</x-button>
                        </div>
                    </form>
                @endcan
            @endif

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Name</th>
                            <th class="pd-th">Email</th>
                            <th class="pd-th">Role</th>
                            <th class="pd-th">Client binding</th>
                            <th class="pd-th">Joined</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @foreach ($users as $user)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap font-medium text-slate-900">{{ $user->name }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">{{ $user->email }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if (! $user->is(auth()->user()) && auth()->user()->can('assignRole', $user))
                                        <select class="border-slate-300 rounded-md shadow-sm text-sm"
                                                aria-label="Role for {{ $user->name }}"
                                                wire:change="setRole({{ $user->id }}, $event.target.value)">
                                            <option value="" @selected($user->roles->isEmpty())>— none —</option>
                                            @foreach ($roles as $role)
                                                <option value="{{ $role }}" @selected($user->hasRole($role))>{{ $role }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-sm text-slate-600">{{ $user->getRoleNames()->join(', ') ?: '—' }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if (! $user->is(auth()->user()) && auth()->user()->can('assignRole', $user))
                                        <select class="border-slate-300 rounded-md shadow-sm text-sm"
                                                aria-label="Client binding for {{ $user->name }}"
                                                wire:change="setClient({{ $user->id }}, $event.target.value)">
                                            <option value="" @selected($user->client_id === null)>— staff (all clients) —</option>
                                            @foreach ($clients as $client)
                                                <option value="{{ $client->id }}" @selected($user->client_id === $client->id)>{{ $client->company_name }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-sm text-slate-600">{{ $user->client?->company_name ?? '—' }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">{{ $user->created_at->format('Y-m-d') }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-right">
                                    @if (auth()->user()->hasRole(\App\Enums\Role::SuperAdmin->value)
                                        && ! $user->is(auth()->user())
                                        && ! $user->hasRole(\App\Enums\Role::SuperAdmin->value)
                                        && ! session()->has(\App\Http\Controllers\ImpersonationController::SESSION_KEY))
                                        <form method="POST" action="{{ route('impersonate.start', $user) }}" target="_blank" class="inline">
                                            @csrf
                                            <button type="submit" class="pd-icon-btn pd-icon-btn-amber"
                                                    aria-label="Login as {{ $user->name }}" title="Login as {{ $user->name }}">
                                                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
                                                <span class="pd-tooltip" role="tooltip">Login as {{ $user->name }}</span>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>

            {{ $users->links() }}
        </div>
    </div>
</div>
