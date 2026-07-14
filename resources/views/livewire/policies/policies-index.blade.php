<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Policies') }}</h2>
            @can('create', \App\Models\SoftwarePolicy::class)
                <a href="{{ route('policies.create') }}"
                   class="inline-flex items-center px-4 py-2 bg-teal-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-500">
                    New policy
                </a>
            @endcan
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
                Policies keep the fleet in a desired state: they queue install, update or remove jobs
                for any machine that drifts, automatically each time an agent reports its software —
                new machines self-provision on first check-in.
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search package or project…" aria-label="Search policies"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-72">
                <select wire:model.live="projectFilter" aria-label="Filter by project"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Policy</th>
                            <th class="pd-th">Project</th>
                            <th class="pd-th">Action</th>
                            <th class="pd-th">Priority</th>
                            <th class="pd-th">Last enforced</th>
                            <th class="pd-th">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($policies as $policy)
                            <tr @class(['opacity-60' => ! $policy->is_active])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="font-medium text-slate-800">{{ $policy->label() }}</span>
                                    <p class="text-xs text-slate-400">{{ $policy->package->vendor }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">
                                    {{ $policy->project->name }}
                                    <p class="text-xs text-slate-400">{{ $policy->project->client->company_name }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $actionBadge = match ($policy->action) {
                                            \App\Enums\JobAction::Install => 'bg-teal-50 text-teal-700 border-teal-200',
                                            \App\Enums\JobAction::Update => 'bg-blue-50 text-blue-700 border-blue-200',
                                            \App\Enums\JobAction::Uninstall => 'bg-red-50 text-red-700 border-red-200',
                                            default => 'bg-slate-100 text-slate-700 border-slate-200',
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $actionBadge }}">{{ $policy->action->label() }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">{{ $policy->priority }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 text-sm"
                                    title="{{ $policy->last_enforced_at }}">
                                    {{ $policy->last_enforced_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $policy->is_active ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200' }}">
                                        {{ $policy->is_active ? 'Active' : 'Disabled' }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-1">
                                    @can('enforce', $policy)
                                        @if ($policy->is_active)
                                            <x-icon-button icon="play" label="Enforce now"
                                                           wire:click="enforceNow({{ $policy->id }})"
                                                           wire:loading.attr="disabled" />
                                        @endif
                                    @endcan
                                    @can('update', $policy)
                                        <x-icon-button icon="power" variant="amber"
                                                       label="{{ $policy->is_active ? 'Disable' : 'Enable' }}"
                                                       wire:click="toggle({{ $policy->id }})" />
                                        <x-icon-button icon="edit" label="Edit"
                                                       href="{{ route('policies.edit', $policy) }}" />
                                    @endcan
                                    @can('delete', $policy)
                                        <x-icon-button icon="delete" variant="danger" label="Delete"
                                                       wire:click="delete({{ $policy->id }})"
                                                       wire:confirm="Delete this policy? Queued jobs are unaffected." />
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">
                                No policies yet. Create one — e.g. “Auto Update Chrome” or “Remove Java” — and it
                                applies to every machine in the project.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $policies->links() }}
        </div>
    </div>
</div>
