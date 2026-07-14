<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Roles & Permissions') }}</h2>
            <button type="button" wire:click="resetDefaults"
                    wire:confirm="Reset every role to the platform default permissions? Custom changes will be lost."
                    class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                Reset to defaults
            </button>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                Tick a box to grant, untick to revoke — changes apply <strong>immediately</strong> to every user
                holding the role. <strong>Super Admin</strong> is not listed: it always has full access.
                Client-bound users additionally only ever see their own client's data, whatever their role.
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Permission</th>
                            @foreach ($roles as $role)
                                <th class="pd-th text-center">{{ $role }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @foreach ($modules as $module => $permissions)
                            <tr class="bg-slate-50/60">
                                <td colspan="{{ count($roles) + 1 }}"
                                    class="px-6 py-2 text-xs font-semibold uppercase tracking-wider text-slate-500">
                                    {{ ucfirst($module) }}
                                </td>
                            </tr>
                            @foreach ($permissions as $permission)
                                <tr wire:key="perm-{{ $permission['value'] }}">
                                    <td class="px-6 py-2.5 whitespace-nowrap text-slate-700">
                                        {{ $permission['label'] }}
                                        <span class="ml-1 text-xs text-slate-400 font-mono">{{ $permission['value'] }}</span>
                                    </td>
                                    @foreach ($roles as $role)
                                        <td class="px-6 py-2.5 text-center">
                                            <input type="checkbox"
                                                   class="rounded border-slate-300 text-teal-600 shadow-sm focus:ring-teal-500 cursor-pointer disabled:opacity-40"
                                                   aria-label="{{ $permission['label'] }} for {{ $role }}"
                                                   @checked(isset($granted[$role][$permission['value']]))
                                                   wire:click="toggle('{{ $role }}', '{{ $permission['value'] }}')"
                                                   wire:loading.attr="disabled">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
