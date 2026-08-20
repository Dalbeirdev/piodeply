<div>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">
                {{ __('Users') }}
            </h2>
            <p class="text-sm text-slate-500 mt-0.5">{{ $stats['total'] }} {{ Str::plural('user', $stats['total']) }}</p>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Same account-hygiene facts `php artisan security:check` already
                 computes, shown where the fix actually happens: the role and
                 client controls on each row below. --}}
            @php
                $userCards = [
                    ['label' => 'Without 2FA', 'value' => $stats['no_2fa'], 'sub' => 'Not enrolled', 'tone' => $stats['no_2fa'] > 0 ? 'amber' : 'slate', 'filter' => 'no_2fa'],
                    ['label' => 'Unbound client accounts', 'value' => $stats['unbound'], 'sub' => 'Client role, no client set', 'tone' => $stats['unbound'] > 0 ? 'red' : 'slate', 'filter' => 'unbound'],
                ];
                $userTones = [
                    'amber' => 'bg-amber-50 text-amber-700 border-amber-100',
                    'red'   => 'bg-red-50 text-red-700 border-red-100',
                    'slate' => 'bg-slate-100 text-slate-600 border-slate-200',
                ];
            @endphp
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-xl">
                @foreach ($userCards as $card)
                    <button type="button" wire:click="$set('statusFilter', '{{ $statusFilter === $card['filter'] ? '' : $card['filter'] }}')"
                        @class([
                            'pd-card p-4 block text-left w-full hover:border-teal-200 transition-colors cursor-pointer',
                            'ring-2 ring-teal-500' => $statusFilter === $card['filter'],
                        ])>
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border {{ $userTones[$card['tone']] }}">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4M12 17h.01"/></svg>
                            </span>
                            <p class="text-sm font-semibold text-slate-700 leading-tight">{{ $card['label'] }}</p>
                        </div>
                        <p class="text-2xl font-bold text-slate-900 tabular-nums mt-2">{{ number_format($card['value']) }}</p>
                        <p class="text-xs text-slate-400">{{ $card['sub'] }}</p>
                    </button>
                @endforeach
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Search name or email…" aria-label="Search users"
                           class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-80">
                    @if ($statusFilter !== '')
                        <button type="button" wire:click="$set('statusFilter', '')" class="text-xs pd-action">Clear filter</button>
                    @endif
                </div>
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

            {{-- Rows, not a 7-column table: identity on the left, the two
                 editable controls on the right. Everything wraps on narrow
                 screens — no horizontal scrollbar, ever. --}}
            <div class="pd-card divide-y divide-slate-100">
                @forelse ($users as $user)
                    <div class="px-6 py-4 flex flex-wrap items-center gap-x-6 gap-y-3">
                        {{-- Identity --}}
                        <div class="flex items-center gap-3 min-w-[16rem] flex-1">
                            <div class="h-10 w-10 shrink-0 grid place-content-center rounded-full bg-teal-50 border border-teal-100 text-teal-700 font-semibold">
                                {{ mb_strtoupper(mb_substr(trim($user->name), 0, 1)) }}
                            </div>
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900 truncate">
                                    {{ $user->name }}
                                    @if ($user->is(auth()->user()))
                                        <span class="text-xs font-normal text-slate-400">(you)</span>
                                    @endif
                                    @if ($user->two_factor_confirmed_at !== null)
                                        <span class="ml-1 align-middle inline-flex items-center text-[10px] font-semibold rounded-full px-1.5 py-0.5 border bg-green-50 text-green-700 border-green-200"
                                              title="Two-factor enabled {{ $user->two_factor_confirmed_at->format('Y-m-d') }}">2FA</span>
                                    @endif
                                </p>
                                <p class="text-sm text-slate-500 truncate">{{ $user->email }}
                                    <span class="text-xs text-slate-400">· joined {{ $user->created_at->format('M j, Y') }}</span>
                                </p>
                            </div>
                        </div>

                        {{-- Controls --}}
                        <div class="flex items-end gap-3 flex-wrap">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400 mb-1">Role</p>
                                @if (! $user->is(auth()->user()) && auth()->user()->can('assignRole', $user))
                                    <select class="border-slate-300 rounded-md shadow-sm text-sm py-1.5 w-40"
                                            aria-label="Role for {{ $user->name }}"
                                            wire:change="setRole({{ $user->id }}, $event.target.value)">
                                        <option value="" @selected($user->roles->isEmpty())>— none —</option>
                                        @foreach ($roles as $role)
                                            <option value="{{ $role }}" @selected($user->hasRole($role))>{{ $role }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <p class="text-sm text-slate-600 py-1.5">{{ $user->getRoleNames()->join(', ') ?: '—' }}</p>
                                @endif
                            </div>
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400 mb-1">Client</p>
                                @if (! $user->is(auth()->user()) && auth()->user()->can('assignRole', $user))
                                    <select class="border-slate-300 rounded-md shadow-sm text-sm py-1.5 w-52"
                                            aria-label="Client binding for {{ $user->name }}"
                                            wire:change="setClient({{ $user->id }}, $event.target.value)">
                                        <option value="" @selected($user->client_id === null)>— staff (all clients) —</option>
                                        @foreach ($clients as $client)
                                            <option value="{{ $client->id }}" @selected($user->client_id === $client->id)>{{ $client->company_name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <p class="text-sm text-slate-600 py-1.5">{{ $user->client?->company_name ?? '—' }}</p>
                                @endif
                            </div>
                            <div class="pb-0.5">
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
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-6 py-12 text-center text-slate-400">No users match this filter.</div>
                @endforelse
            </div>

            {{ $users->links() }}
        </div>
    </div>
</div>
