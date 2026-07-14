<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Users') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex items-center justify-between">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search name or email…" aria-label="Search users"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-80">
                <span x-data="{ shown: false }"
                      x-on:role-updated.window="shown = true; setTimeout(() => shown = false, 2000)"
                      x-show="shown" x-transition
                      class="text-sm text-green-600 font-medium" style="display:none">
                    Role updated.
                </span>
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Name</th>
                            <th class="pd-th">Email</th>
                            <th class="pd-th">Role</th>
                            <th class="pd-th">Client binding</th>
                            <th class="pd-th">Joined</th>
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
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>

            {{ $users->links() }}
        </div>
    </div>
</div>
