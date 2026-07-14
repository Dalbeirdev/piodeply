<div wire:poll.30s>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-slate-900 leading-tight">Dashboard</h2>
            <p class="text-sm text-slate-500 mt-0.5">Fleet, deployments and activity at a glance</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-5">

            {{-- Primary tiles --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="{{ route('computers.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <div class="flex items-center justify-between">
                        <p class="text-2xl font-bold text-emerald-600 leading-tight">{{ $stats['online'] }}</p>
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    </div>
                    <p class="text-sm font-semibold text-slate-700">Computers online</p>
                    <p class="text-xs text-slate-400">heartbeat within {{ round(\App\Models\Computer::onlineThreshold() / 60) }} min</p>
                </a>
                <a href="{{ route('computers.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <div class="flex items-center justify-between">
                        <p class="text-2xl font-bold {{ $stats['offline'] > 0 ? 'text-slate-600' : 'text-slate-300' }} leading-tight">{{ $stats['offline'] }}</p>
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-300" aria-hidden="true"></span>
                    </div>
                    <p class="text-sm font-semibold text-slate-700">Computers offline</p>
                    <p class="text-xs text-slate-400">no recent heartbeat</p>
                </a>
                <a href="{{ route('deployments.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-2xl font-bold text-sky-600 leading-tight">{{ $stats['pending'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Jobs in flight</p>
                    <p class="text-xs text-slate-400">pending / running / blocked</p>
                </a>
                <a href="{{ route('deployments.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-2xl font-bold {{ $stats['failed'] > 0 ? 'text-red-600' : 'text-slate-300' }} leading-tight">{{ $stats['failed'] }}</p>
                    <p class="text-sm font-semibold text-slate-700">Failed jobs</p>
                    <p class="text-xs text-slate-400">out of retries — need attention</p>
                </a>
            </div>

            {{-- Secondary tiles --}}
            <div class="grid grid-cols-2 md:grid-cols-6 gap-4">
                <div class="pd-card p-4">
                    <p class="text-xl font-bold {{ $stats['outdated'] > 0 ? 'text-amber-600' : 'text-slate-300' }}">{{ $stats['outdated'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Outdated software</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-xl font-bold text-slate-700">{{ number_format($stats['software']) }}</p>
                    <p class="text-xs font-semibold text-slate-600">Software detected</p>
                </div>
                <div class="pd-card p-4">
                    <p class="text-xl font-bold text-slate-700">{{ $stats['licenses'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Commercial installs</p>
                </div>
                <a href="{{ route('clients.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold text-slate-700">{{ $stats['clients'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Clients</p>
                </a>
                <a href="{{ route('projects.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold text-slate-700">{{ $stats['projects'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Projects</p>
                </a>
                <a href="{{ route('packages.index') }}" class="pd-card p-4 hover:border-teal-300 transition-colors">
                    <p class="text-xl font-bold text-slate-700">{{ $stats['packages'] }}</p>
                    <p class="text-xs font-semibold text-slate-600">Active packages</p>
                </a>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                {{-- Fleet by client: stacked horizontal bars --}}
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Fleet by client</h3>
                        <div class="flex items-center gap-3 text-xs text-slate-500">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-teal-600"></span>Online</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-300"></span>Offline</span>
                        </div>
                    </div>
                    @if (count($fleetByClient) === 0)
                        <p class="text-sm text-slate-400 py-6 text-center">No enrolled computers yet.</p>
                    @else
                        @php $fleetMax = max(1, max(array_column($fleetByClient, 'total'))); @endphp
                        <div class="space-y-2.5 mt-3" role="img"
                             aria-label="Computers per client: {{ collect($fleetByClient)->map(fn ($r) => "{$r['name']} {$r['online']} online, {$r['offline']} offline")->join('; ') }}">
                            @foreach ($fleetByClient as $row)
                                <div class="grid grid-cols-[8rem_1fr_3.5rem] items-center gap-3 text-xs">
                                    <span class="text-slate-500 truncate text-right" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                    <span class="flex h-3.5 rounded-full bg-slate-100 overflow-hidden">
                                        @if ($row['online'] > 0)
                                            <span class="bg-teal-600 h-full" style="width: {{ $row['online'] / $fleetMax * 100 }}%"></span>
                                        @endif
                                        @if ($row['offline'] > 0)
                                            <span class="bg-slate-300 h-full border-l-2 border-white" style="width: {{ $row['offline'] / $fleetMax * 100 }}%"></span>
                                        @endif
                                    </span>
                                    <span class="font-mono text-slate-600">{{ $row['online'] }}/{{ $row['total'] }}</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="text-xs text-slate-400 mt-3">online / total per client</p>
                    @endif
                </div>

                {{-- Deployments last 14 days: stacked columns --}}
                <div class="pd-card p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">Deployments — last 14 days</h3>
                        <div class="flex items-center gap-3 text-xs text-slate-500">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-teal-600"></span>Succeeded</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-400"></span>Failed</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-300"></span>Other</span>
                        </div>
                    </div>
                    @php $seriesMax = max(1, max(array_map(fn ($d) => $d['succeeded'] + $d['failed'] + $d['other'], $series))); @endphp
                    <div class="flex items-end gap-1.5 h-36 mt-4" role="img"
                         aria-label="Deployment jobs per day: {{ collect($series)->map(fn ($d) => "{$d['label']}: {$d['succeeded']} succeeded, {$d['failed']} failed, {$d['other']} other")->join('; ') }}">
                        @foreach ($series as $day)
                            @php $dayTotal = $day['succeeded'] + $day['failed'] + $day['other']; @endphp
                            <div class="flex-1 flex flex-col justify-end h-full group relative"
                                 title="{{ $day['label'] }}: {{ $day['succeeded'] }} ok · {{ $day['failed'] }} failed · {{ $day['other'] }} other">
                                @if ($dayTotal === 0)
                                    <div class="bg-slate-100 rounded-sm" style="height: 3px"></div>
                                @else
                                    @if ($day['other'] > 0)
                                        <div class="bg-slate-300 rounded-t-sm" style="height: {{ $day['other'] / $seriesMax * 100 }}%"></div>
                                    @endif
                                    @if ($day['failed'] > 0)
                                        <div class="bg-red-400 {{ $day['other'] === 0 ? 'rounded-t-sm' : '' }} border-t border-white" style="height: {{ $day['failed'] / $seriesMax * 100 }}%"></div>
                                    @endif
                                    @if ($day['succeeded'] > 0)
                                        <div class="bg-teal-600 {{ $day['failed'] === 0 && $day['other'] === 0 ? 'rounded-t-sm' : '' }} border-t border-white" style="height: {{ $day['succeeded'] / $seriesMax * 100 }}%"></div>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-between text-[10px] text-slate-400 mt-1.5">
                        <span>{{ $series[0]['label'] }}</span>
                        <span>{{ $series[count($series) - 1]['label'] }}</span>
                    </div>
                </div>
            </div>

            {{-- Today's activity --}}
            <div class="pd-card p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide">
                        Recent activity
                        <span class="ml-1 text-slate-400 font-normal normal-case">({{ $stats['today'] }} today)</span>
                    </h3>
                </div>
                @if ($activity->isEmpty())
                    <p class="text-sm text-slate-400">Nothing recorded yet.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($activity as $entry)
                            <li class="py-2 flex items-center justify-between gap-3 text-sm">
                                <span class="text-slate-700">
                                    <span class="pd-badge pd-badge-slate mr-1.5">{{ $entry->log_name }}</span>
                                    {{ str_replace('_', ' ', $entry->description) }}
                                    @if ($entry->causer)
                                        <span class="text-slate-400">by {{ $entry->causer->name }}</span>
                                    @endif
                                </span>
                                <span class="text-slate-400 whitespace-nowrap text-xs">{{ $entry->created_at->diffForHumans(short: true) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
