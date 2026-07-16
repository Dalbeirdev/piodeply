<div wire:poll.10s>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Deployments') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Search computer or package…" aria-label="Search deployments"
                       class="border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm w-72">
                <select wire:model.live="status" aria-label="Filter by status"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                <select wire:model.live="action" aria-label="Filter by action"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All actions</option>
                    @foreach ($actions as $a)
                        <option value="{{ $a->value }}">{{ $a->label() }}</option>
                    @endforeach
                </select>

                <label class="flex items-center gap-2 text-sm text-slate-600 select-none ml-auto">
                    <input type="checkbox" wire:model.live="history"
                           class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Show full history
                </label>
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Computer</th>
                            <th class="pd-th">Package</th>
                            <th class="pd-th">Action</th>
                            <th class="pd-th">Priority</th>
                            <th class="pd-th">Attempts</th>
                            <th class="pd-th">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($jobs as $job)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('computers.show', $job->computer) }}"
                                       class="pd-link">{{ $job->computer->hostname }}</a>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-700">
                                    {{ $job->package->name }}
                                    @if ($label = $job->versionLabel())
                                        <span class="block text-xs text-slate-400 font-mono">{{ $label }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">
                                    {{ $job->action->label() }}
                                    @if (! $history && $job->repeat_count > 1)
                                        <span class="ml-1 text-xs text-slate-400"
                                              title="Requested {{ $job->repeat_count }} times — showing the latest">
                                            ×{{ $job->repeat_count }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">{{ $job->priority }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">{{ $job->attempts }}/{{ $job->max_attempts }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $badge = match ($job->status) {
                                            \App\Enums\JobStatus::Succeeded => 'bg-green-50 text-green-700 border-green-200',
                                            \App\Enums\JobStatus::Failed => 'bg-red-50 text-red-700 border-red-200',
                                            \App\Enums\JobStatus::Running => 'bg-blue-50 text-blue-700 border-blue-200',
                                            \App\Enums\JobStatus::Blocked => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                                            \App\Enums\JobStatus::Cancelled => 'bg-slate-100 text-slate-500 border-slate-200',
                                            default => 'bg-slate-100 text-slate-700 border-slate-200',
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $badge }}">{{ $job->status->label() }}</span>
                                    @if ($job->failure_reason)
                                        <p class="text-xs text-red-500 max-w-xs truncate" title="{{ $job->failure_reason }}">{{ $job->failure_reason }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-1">
                                    @can('manage', $job)
                                        @if (in_array($job->status, [\App\Enums\JobStatus::Failed, \App\Enums\JobStatus::Cancelled], true))
                                            <x-icon-button icon="retry" label="Retry" wire:click="retry({{ $job->id }})" />
                                        @elseif (! $job->status->isTerminal())
                                            <x-icon-button icon="cancel" variant="danger" label="Cancel"
                                                           wire:click="cancel({{ $job->id }})"
                                                           wire:confirm="Cancel this job?" />
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-slate-500">No deployment jobs yet. Queue one from a computer's page.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $jobs->links() }}
        </div>
    </div>
</div>
