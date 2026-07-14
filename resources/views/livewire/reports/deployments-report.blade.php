<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">Deployment activity report</h2>
            @can(\App\Enums\Permission::ReportsExport->value)
                <button type="button" wire:click="export"
                        class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                    Export CSV
                </button>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="from" class="block text-xs text-slate-500 mb-1">From</label>
                    <input id="from" type="date" wire:model.live="from" class="border-slate-300 rounded-md shadow-sm text-sm">
                </div>
                <div>
                    <label for="to" class="block text-xs text-slate-500 mb-1">To</label>
                    <input id="to" type="date" wire:model.live="to" class="border-slate-300 rounded-md shadow-sm text-sm">
                </div>
                <select wire:model.live="status" aria-label="Filter by status"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                <select wire:model.live="projectFilter" aria-label="Filter by project"
                        class="border-slate-300 rounded-md shadow-sm text-sm">
                    <option value="">All projects</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Jobs</p>
                    <p class="text-2xl font-bold text-slate-800">{{ $stats['total'] }}</p></div>
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Succeeded</p>
                    <p class="text-2xl font-bold text-green-600">{{ $stats['succeeded'] }}</p></div>
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Failed</p>
                    <p class="text-2xl font-bold text-red-600">{{ $stats['failed'] }}</p></div>
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">In flight</p>
                    <p class="text-2xl font-bold text-blue-600">{{ $stats['in_flight'] }}</p></div>
                <div class="pd-card p-4"><p class="text-xs uppercase tracking-wider text-slate-400">Success rate</p>
                    @php $rate = $stats['success_rate']; @endphp
                    <p class="text-2xl font-bold {{ $rate === null ? 'text-slate-400' : ($rate >= 95 ? 'text-green-600' : ($rate >= 80 ? 'text-amber-600' : 'text-red-600')) }}">
                        {{ $rate === null ? '—' : $rate . '%' }}
                    </p></div>
            </div>

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Date</th>
                            <th class="pd-th">Computer</th>
                            <th class="pd-th">Package</th>
                            <th class="pd-th">Action</th>
                            <th class="pd-th">Status</th>
                            <th class="pd-th">Detail</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($jobs as $job)
                            <tr>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 text-sm">{{ $job->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <a href="{{ route('computers.show', $job->computer) }}" class="pd-link">{{ $job->computer->hostname }}</a>
                                    <p class="text-xs text-slate-400">{{ $job->computer->project->client->company_name }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-700">
                                    {{ $job->package->name }}
                                    @if ($job->target_version)<span class="text-xs text-slate-400">{{ $job->target_version }}</span>@endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600">{{ $job->action->label() }}</td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @php
                                        $badge = match ($job->status) {
                                            \App\Enums\JobStatus::Succeeded => 'bg-green-50 text-green-700 border-green-200',
                                            \App\Enums\JobStatus::Failed => 'bg-red-50 text-red-700 border-red-200',
                                            \App\Enums\JobStatus::Running => 'bg-blue-50 text-blue-700 border-blue-200',
                                            \App\Enums\JobStatus::Cancelled => 'bg-slate-100 text-slate-500 border-slate-200',
                                            default => 'bg-slate-100 text-slate-700 border-slate-200',
                                        };
                                    @endphp
                                    <span class="text-xs font-semibold rounded-full px-2 py-0.5 border {{ $badge }}">{{ $job->status->label() }}</span>
                                </td>
                                <td class="px-6 py-3 text-slate-500 text-sm max-w-xs truncate" title="{{ $job->failure_reason }}">
                                    {{ $job->failure_reason ?? ($job->exit_code !== null ? "exit {$job->exit_code}" : '—') }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">No jobs in this range.</td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>

            {{ $jobs->links() }}
        </div>
    </div>
</div>
