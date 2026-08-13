<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 10px; color: #1e293b; margin: 24px; }
    h1 { font-size: 16px; margin: 0 0 2px; }
    .muted { color: #64748b; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .05em;
         color: #64748b; border-bottom: 1.5px solid #cbd5e1; padding: 4px 6px; }
    td { border-bottom: 0.5px solid #e2e8f0; padding: 5px 6px; vertical-align: top; }
    .score { font-weight: bold; font-size: 12px; }
    .good { color: #15803d; } .warn { color: #b45309; } .bad { color: #b91c1c; }
    .notes { color: #64748b; font-size: 8.5px; }
</style>
</head>
<body>
    <h1>Fleet health report</h1>
    <p class="muted">{{ $company }} &middot; generated {{ $generatedAt->format('Y-m-d H:i') }} &middot; {{ $computers->count() }} {{ \Illuminate\Support\Str::plural('machine', $computers->count()) }}</p>

    <table>
        <thead>
            <tr>
                <th>Health</th>
                <th>Computer</th>
                <th>Client / {{ project_term() }}</th>
                <th>Agent</th>
                <th>Last seen</th>
                <th>Disk free</th>
                <th>Attention needed</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($computers as $computer)
                @php
                    $health = $computer->healthScore();
                    $class = $health['score'] >= 90 ? 'good' : ($health['score'] >= 70 ? 'warn' : 'bad');
                    $diskPct = ($computer->disk_total_bytes && $computer->disk_free_bytes !== null)
                        ? round($computer->disk_free_bytes / $computer->disk_total_bytes * 100).'%'
                        : '—';
                @endphp
                <tr>
                    <td class="score {{ $class }}">{{ $health['score'] }}<span style="font-size:8px; color:#94a3b8;">/100</span></td>
                    <td><b>{{ $computer->hostname }}</b><br><span class="muted">{{ $computer->os_name }}</span></td>
                    <td>{{ $computer->project->client->company_name }} / {{ $computer->project->name }}</td>
                    <td>{{ $computer->agent_version ?? '—' }} {{ $computer->isOnline() ? '(online)' : '(offline)' }}</td>
                    <td>{{ $computer->last_seen_at?->format('Y-m-d H:i') ?? 'never' }}</td>
                    <td>{{ $diskPct }}</td>
                    <td class="notes">{{ $health['notes'] === [] ? 'Nothing — healthy.' : implode(' · ', $health['notes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
