<div wire:poll.10s>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Deployments') }}</h2>
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
                       class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm w-72">
                <select wire:model.live="status" aria-label="Filter by status"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                <select wire:model.live="action" aria-label="Filter by action"
                        class="border-gray-300 rounded-md shadow-sm text-sm">
                    <option value="">All actions</option>
                    @foreach ($actions as $a)
                        <option value="{{ $a->value }}">{{ $a->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Computer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Package</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attempts</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        @forelse ($jobs as $job)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('computers.show', $job->computer) }}"
                                       class="text-indigo-700 hover:underline">{{ $job->computer->hostname }}</a>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-700">
                                    {{ $job->package->name }}
                                    <span class="text-xs text-gray-400">{{ $job->packageVersion?->version }}</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600">{{ $job->action->label() }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600">{{ $job->priority }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-gray-600">{{ $job->attempts }}/{{ $job->max_attempts }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $badge = match ($job->status) {
                                            \App\Enums\JobStatus::Succeeded => 'bg-green-50 text-green-700 border-green-200',
                                            \App\Enums\JobStatus::Failed => 'bg-red-50 text-red-700 border-red-200',
                                            \App\Enums\JobStatus::Running => 'bg-blue-50 text-blue-700 border-blue-200',
                                            \App\Enums\JobStatus::Blocked => 'bg-yellow-50 text-yellow-700 border-yellow-200',
                                            \App\Enums\JobStatus::Cancelled => 'bg-gray-100 text-gray-500 border-gray-200',
                                            default => 'bg-gray-100 text-gray-700 border-gray-200',
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $badge }}">{{ $job->status->label() }}</span>
                                    @if ($job->failure_reason)
                                        <p class="text-xs text-red-500 max-w-xs truncate" title="{{ $job->failure_reason }}">{{ $job->failure_reason }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-2">
                                    @can('manage', $job)
                                        @if (in_array($job->status, [\App\Enums\JobStatus::Failed, \App\Enums\JobStatus::Cancelled], true))
                                            <button wire:click="retry({{ $job->id }})" class="font-semibold text-indigo-600 hover:underline">Retry</button>
                                        @elseif (! $job->status->isTerminal())
                                            <button wire:click="cancel({{ $job->id }})"
                                                    wire:confirm="Cancel this job?"
                                                    class="font-semibold text-red-600 hover:underline">Cancel</button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">No deployment jobs yet. Queue one from a computer's page.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{ $jobs->links() }}
        </div>
    </div>
</div>
