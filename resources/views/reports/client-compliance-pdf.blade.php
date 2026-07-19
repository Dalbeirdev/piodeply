<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    /* dompdf: tables + basic CSS only — no flexbox/grid. */
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; margin: 0; }
    .head { background: #0f172a; color: #ffffff; padding: 22px 28px; }
    .head h1 { margin: 0; font-size: 20px; }
    .head .sub { color: #94a3b8; font-size: 11px; margin-top: 4px; }
    .brand { color: #5eead4; font-size: 12px; font-weight: bold; margin-bottom: 8px; }
    .wrap { padding: 20px 28px; }
    h2 { font-size: 13px; color: #0f172a; border-bottom: 2px solid #14b8a6; padding-bottom: 4px; margin: 22px 0 8px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 10px; text-transform: uppercase; color: #64748b; padding: 5px 8px; border-bottom: 1px solid #cbd5e1; }
    td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; }
    .tiles td { border: 1px solid #e2e8f0; text-align: center; padding: 10px 6px; width: 25%; }
    .tiles .v { font-size: 18px; font-weight: bold; color: #0f172a; }
    .tiles .k { font-size: 9px; text-transform: uppercase; color: #64748b; }
    .good { color: #047857; font-weight: bold; }
    .warn { color: #b45309; font-weight: bold; }
    .bad { color: #b91c1c; font-weight: bold; }
    .muted { color: #94a3b8; }
    .foot { margin-top: 26px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; }
</style>
</head>
<body>
    <div class="head">
        <div class="brand">{{ $company }}</div>
        <h1>Compliance report — {{ $client->company_name }}</h1>
        <div class="sub">Reporting period {{ $period }} · generated {{ $generated->format('j F Y') }}</div>
    </div>

    <div class="wrap">
        <h2>Fleet overview</h2>
        <table class="tiles"><tr>
            <td><div class="v">{{ $fleet['total'] }}</div><div class="k">Machines</div></td>
            <td><div class="v">{{ $fleet['online'] }}</div><div class="k">Online</div></td>
            <td><div class="v">{{ $fleet['offline'] }}</div><div class="k">Offline</div></td>
            <td><div class="v">{{ $fleet['agents_outdated'] }}</div><div class="k">Agent updates pending</div></td>
        </tr></table>

        <h2>Software policy compliance</h2>
        @if ($software->isEmpty())
            <p class="muted">No active software policies for this client.</p>
        @else
            <table>
                <thead><tr>
                    <th>Policy</th><th>Project</th>
                    <th class="num">Targeted</th><th class="num">Compliant</th>
                    <th class="num">Pending</th><th class="num">Failed</th><th class="num">%</th>
                </tr></thead>
                <tbody>
                @foreach ($software as $row)
                    <tr>
                        <td>{{ $row['policy']->package->name }} — {{ $row['policy']->action->label() }}</td>
                        <td>{{ $row['policy']->project->name }}</td>
                        <td class="num">{{ $row['summary']['target'] }}</td>
                        <td class="num good">{{ $row['summary']['compliant'] }}</td>
                        <td class="num">{{ $row['summary']['pending'] + $row['summary']['scheduled'] }}</td>
                        <td class="num {{ ($row['summary']['failed'] + $row['summary']['non_compliant']) > 0 ? 'bad' : '' }}">
                            {{ $row['summary']['failed'] + $row['summary']['non_compliant'] }}
                        </td>
                        <td class="num {{ ($row['summary']['percent'] ?? 0) >= 95 ? 'good' : 'warn' }}">
                            {{ $row['summary']['percent'] !== null ? $row['summary']['percent'].'%' : '—' }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <h2>Browser policy compliance</h2>
        @if ($browser['policies'] === 0)
            <p class="muted">No active browser policies for this client.</p>
        @else
            <table class="tiles"><tr>
                <td><div class="v {{ ($browser['percent'] ?? 0) >= 95 ? 'good' : 'warn' }}">{{ $browser['percent'] !== null ? $browser['percent'].'%' : '—' }}</div><div class="k">Protected</div></td>
                <td><div class="v">{{ $browser['protected'] }}</div><div class="k">Compliant</div></td>
                <td><div class="v {{ $browser['non_compliant'] > 0 ? 'bad' : '' }}">{{ $browser['non_compliant'] }}</div><div class="k">Failing</div></td>
                <td><div class="v">{{ $browser['pending'] }}</div><div class="k">Pending</div></td>
            </tr></table>
        @endif

        <h2>Deployment activity (last 30 days)</h2>
        <table class="tiles"><tr>
            <td><div class="v">{{ $deployments['total'] }}</div><div class="k">Jobs</div></td>
            <td><div class="v good">{{ $deployments['succeeded'] }}</div><div class="k">Succeeded</div></td>
            <td><div class="v {{ $deployments['failed'] > 0 ? 'bad' : '' }}">{{ $deployments['failed'] }}</div><div class="k">Failed</div></td>
            <td><div class="v">{{ $deployments['total'] > 0 ? round($deployments['succeeded'] / max(1, $deployments['total']) * 100) : 100 }}%</div><div class="k">Success rate</div></td>
        </tr></table>

        <h2>Machines</h2>
        <table>
            <thead><tr>
                <th>Hostname</th><th>Project</th><th>OS</th><th>Agent</th><th>Last seen</th>
            </tr></thead>
            <tbody>
            @foreach ($computers as $computer)
                <tr>
                    <td>{{ $computer->hostname }}</td>
                    <td>{{ $computer->project->name }}</td>
                    <td>{{ $computer->os_name }}</td>
                    <td>{{ $computer->agent_version ?? '—' }}{{ $computer->isAgentOutdated() ? ' (update pending)' : '' }}</td>
                    <td>{{ $computer->last_seen_at?->format('Y-m-d H:i') ?? 'never' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="foot">
            Generated by {{ $company }} with PioDeploy. Figures reflect agent reports at generation time;
            machines offline at generation keep their last reported state.
        </div>
    </div>
</body>
</html>
