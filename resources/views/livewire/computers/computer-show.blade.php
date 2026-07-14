<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">
                {{ $computer->hostname }}
                @if ($computer->isOnline())
                    <span class="ml-2 align-middle inline-flex items-center gap-1.5 text-xs font-semibold rounded-full px-2 py-0.5 border bg-green-50 text-green-700 border-green-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Online
                    </span>
                @else
                    <span class="ml-2 align-middle inline-flex items-center gap-1.5 text-xs font-semibold rounded-full px-2 py-0.5 border bg-slate-100 text-slate-600 border-slate-200">
                        <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span> Offline
                    </span>
                @endif
            </h2>
            @can('update', $computer)
                <a href="{{ route('computers.edit', $computer) }}"
                   class="text-sm pd-action">Reassign project</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        @can('create', \App\Models\DeploymentJob::class)
            <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 mb-6">
                @livewire('deployments.deploy-to-computer', ['computer' => $computer], key('deploy-'.$computer->id))
            </div>
        @endcan

        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 grid grid-cols-1 md:grid-cols-2 gap-6">
            @php
                $sections = [
                    'Assignment' => [
                        'Client' => $computer->project->client->company_name,
                        'Project' => $computer->project->name,
                        'Agent version' => $computer->agent_version,
                        'Agent UUID' => $computer->agent_uuid,
                        'Last seen' => $computer->last_seen_at?->format('Y-m-d H:i:s') . ' (' . ($computer->last_seen_at?->diffForHumans() ?? 'never') . ')',
                    ],
                    'System' => [
                        'Manufacturer' => $computer->manufacturer,
                        'Model' => $computer->model,
                        'Serial number' => $computer->serial_number,
                        'OS' => $computer->os_name,
                        'OS version' => $computer->os_version,
                        'Windows build' => $computer->windows_build,
                    ],
                    'Hardware' => [
                        'CPU' => $computer->cpu,
                        'RAM' => $computer->ramForHumans(),
                        'Disk' => $computer->diskForHumans(),
                    ],
                    'Network' => [
                        'Private IP' => $computer->private_ip,
                        'Public IP' => $computer->public_ip,
                        'MAC address' => $computer->mac_address,
                    ],
                ];
            @endphp

            @foreach ($sections as $title => $rows)
                <div class="pd-card p-6">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">{{ $title }}</h3>
                    <dl class="space-y-2">
                        @foreach ($rows as $label => $value)
                            <div class="flex justify-between gap-4 text-sm">
                                <dt class="text-slate-500">{{ $label }}</dt>
                                <dd class="text-slate-900 text-right break-all">{{ $value ?? '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach

            <div class="pd-card p-6">
                <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wide mb-3">Security posture</h3>
                <dl class="space-y-2">
                    <div class="flex justify-between gap-4 text-sm">
                        <dt class="text-slate-500">Secure Boot</dt>
                        <dd>
                            @if ($computer->secure_boot === null) <span class="text-slate-400">unknown</span>
                            @elseif ($computer->secure_boot) <span class="text-green-700 font-semibold">Enabled</span>
                            @else <span class="text-red-600 font-semibold">Disabled</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 text-sm">
                        <dt class="text-slate-500">TPM</dt>
                        <dd>
                            @if ($computer->tpm_enabled === null) <span class="text-slate-400">unknown</span>
                            @elseif ($computer->tpm_enabled) <span class="text-green-700 font-semibold">Enabled{{ $computer->tpm_version ? ' (v' . $computer->tpm_version . ')' : '' }}</span>
                            @else <span class="text-red-600 font-semibold">Disabled</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
