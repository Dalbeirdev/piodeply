<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Browser Policies') }}</h2>
            <div class="flex items-center gap-2">
                <a href="{{ route('browser-policies.compliance') }}"
                   class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                    Compliance
                </a>
                @can('create', \App\Models\BrowserPolicy::class)
                    <a href="{{ route('browser-policies.templates') }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Templates
                    </a>
                    <a href="{{ route('browser-policies.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-teal-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-500">
                        New policy
                    </a>
                @endcan
            </div>
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
                Enterprise browser restrictions — block incognito/private browsing, guest mode, password
                saving or developer tools — applied by the agent through Windows enterprise policy
                (registry / Firefox <code class="font-mono text-xs">policies.json</code>), like Group Policy
                but without a domain. Agents apply changes on their next check-in and roll settings back
                when a policy is removed.
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search policy or project…" aria-label="Search browser policies"
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
                            <th class="pd-th">Browsers</th>
                            <th class="pd-th">Compliance</th>
                            <th class="pd-th">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($policies as $policy)
                            @php $summary = $summaries[$policy->id] ?? null; @endphp
                            <tr @class(['opacity-60' => ! $policy->isActive()])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('browser-policies.show', $policy) }}" class="pd-link font-medium">{{ $policy->name }}</a>
                                    <p class="text-xs text-slate-400">{{ $policy->label() }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">
                                    {{ $policy->scopeName() }}
                                    @if ($policy->project !== null)
                                        <p class="text-xs text-slate-400">{{ $policy->project->client->company_name }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if (($policy->browsers ?? []) === ['all'])
                                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-slate-100 text-slate-600 border-slate-200">All browsers</span>
                                    @else
                                        @foreach ($policy->targetBrowsers() as $browser)
                                            <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-slate-100 text-slate-600 border-slate-200">{{ $browser->label() }}</span>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if ($summary === null)
                                        <span class="text-xs text-slate-400">—</span>
                                    @elseif ($summary['target'] === 0)
                                        <span class="text-xs text-slate-400">No computers</span>
                                    @else
                                        @php
                                            $pct = $summary['percent'];
                                            $pctColor = $pct >= 90 ? 'text-green-600' : ($pct >= 60 ? 'text-amber-600' : 'text-red-600');
                                        @endphp
                                        <a href="{{ route('browser-policies.show', $policy) }}" class="group">
                                            <span class="font-semibold {{ $pctColor }}">{{ $pct }}%</span>
                                            <span class="text-xs text-slate-500 group-hover:text-slate-700">
                                                {{ $summary['protected'] }}/{{ $summary['target'] }} protected
                                                @if ($summary['non_compliant'] > 0) · <span class="text-red-600">{{ $summary['non_compliant'] }} non-compliant</span> @endif
                                                @if ($summary['pending'] > 0) · {{ $summary['pending'] }} pending @endif
                                            </span>
                                        </a>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $policy->isActive() ? 'bg-green-50 text-green-700 border-green-200' : 'bg-slate-100 text-slate-500 border-slate-200' }}">
                                        {{ ucfirst($policy->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-1">
                                    @can('update', $policy)
                                        <x-icon-button icon="power" variant="amber"
                                                       label="{{ $policy->isActive() ? 'Deactivate' : 'Activate' }}"
                                                       wire:click="toggle({{ $policy->id }})" />
                                        <x-icon-button icon="edit" label="Edit"
                                                       href="{{ route('browser-policies.edit', $policy) }}" />
                                    @endcan
                                    @can('delete', $policy)
                                        <x-icon-button icon="delete" variant="danger" label="Delete"
                                                       wire:click="delete({{ $policy->id }})"
                                                       wire:confirm="Delete this policy? Agents remove the setting on their next check-in." />
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">
                                No browser policies yet. Create one — e.g. “Disable Incognito Mode” — and every
                                machine in the project locks it down on the next agent check-in.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $policies->links() }}
        </div>
    </div>
</div>
