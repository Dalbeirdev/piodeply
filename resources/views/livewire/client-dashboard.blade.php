<div wire:poll.30s>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="font-semibold text-xl text-slate-900 leading-tight">{{ $client->company_name }} — Portal</h2>
                <p class="text-sm text-slate-500 mt-0.5">Your fleet, deployments and agent downloads</p>
            </div>
            <a href="{{ route('clients.compliance-report', $client) }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50 whitespace-nowrap">
                Compliance report (PDF)
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">

            {{-- Tiles --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="{{ route('computers.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-2xl font-bold text-emerald-600 leading-tight">{{ $stats['online'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Computers online</p>
                </a>
                <a href="{{ route('computers.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-2xl font-bold text-slate-600 leading-tight">{{ $stats['offline'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Computers offline</p>
                </a>
                <a href="{{ route('deployments.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-2xl font-bold text-sky-600 leading-tight">{{ $stats['pending'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Pending updates</p>
                </a>
                <a href="{{ route('deployments.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-2xl font-bold {{ $stats['failed'] > 0 ? 'text-red-600' : 'text-slate-300' }} leading-tight">{{ $stats['failed'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Failed installs</p>
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                {{-- Projects + agent download --}}
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Your projects</h3>
                    @if ($projects->isEmpty())
                        <p class="text-sm text-slate-400">No projects yet — your MSP sets these up.</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach ($projects as $project)
                                <li class="py-3 flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-800">{{ $project->name }}</p>
                                        <p class="text-xs text-slate-400">
                                            {{ $project->computers_count }} {{ Str::plural('computer', $project->computers_count) }}
                                            · {{ $project->status->label() }}
                                        </p>
                                    </div>
                                    <a href="{{ route('projects.enrollment', $project) }}"
                                       class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-teal-700 rounded-lg font-semibold text-xs text-white hover:bg-teal-800 transition">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                                        Enrol machines
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Computers --}}
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Your computers</h3>
                        <a href="{{ route('computers.index') }}" class="text-sm pd-action">View all →</a>
                    </div>
                    @if ($computers->isEmpty())
                        <p class="text-sm text-slate-400">No computers enrolled yet — install the agent to get started.</p>
                    @else
                        <ul class="divide-y divide-slate-100">
                            @foreach ($computers as $computer)
                                <li class="py-2 flex items-center justify-between gap-3 text-sm">
                                    <a href="{{ route('computers.show', $computer) }}" class="pd-link">{{ $computer->hostname }}</a>
                                    <span class="flex items-center gap-3">
                                        <span class="text-xs text-slate-400">{{ $computer->os_name }}</span>
                                        @if ($computer->isOnline())
                                            <span class="pd-badge pd-badge-green"><span class="pd-dot"></span>Online</span>
                                        @else
                                            <span class="pd-badge pd-badge-slate"><span class="pd-dot"></span>Offline</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- History --}}
            <div class="pd-card">
                <div class="flex items-center justify-between px-6 pt-5 pb-2">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Deployment history</h3>
                    <a href="{{ route('deployments.index') }}" class="text-sm pd-action">View all →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="pd-th">Computer</th>
                                <th class="pd-th">Software</th>
                                <th class="pd-th">Action</th>
                                <th class="pd-th">Status</th>
                                <th class="pd-th">When</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($recentJobs as $job)
                                <tr>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm">
                                        <a href="{{ route('computers.show', $job->computer) }}" class="pd-link">{{ $job->computer->hostname }}</a>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-700">{{ $job->package->name }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-600">{{ $job->action->label() }}</td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        @php
                                            $badge = match ($job->status) {
                                                \App\Enums\JobStatus::Succeeded => 'pd-badge-green',
                                                \App\Enums\JobStatus::Failed => 'pd-badge-red',
                                                \App\Enums\JobStatus::Running => 'pd-badge-sky',
                                                default => 'pd-badge-slate',
                                            };
                                        @endphp
                                        <span class="pd-badge {{ $badge }}"><span class="pd-dot"></span>{{ $job->status->label() }}</span>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">{{ $job->created_at->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-6 py-8 text-center text-slate-400">No deployments yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
