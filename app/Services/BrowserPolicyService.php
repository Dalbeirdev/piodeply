<?php

namespace App\Services;

use App\Models\BrowserPolicy;
use App\Models\BrowserPolicyResult;
use App\Models\Computer;
use Illuminate\Support\Collection;

/**
 * Browser-policy pipeline: compiles the desired-state document an agent
 * applies, ingests its per-browser results, and aggregates compliance.
 */
class BrowserPolicyService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {
    }

    /* ─────────────────────── Agent document ──────────────────────────── */

    /**
     * Everything this machine should enforce. The agent treats it as the
     * complete desired state: settings it applied before that are missing
     * here get rolled back.
     *
     * @return array{policies: list<array{policy_id: int, type: string, action: string, operations: array<string, array>}>}
     */
    public function documentFor(Computer $computer): array
    {
        $policies = BrowserPolicy::query()
            ->where('project_id', $computer->project_id)
            ->where('status', 'active')
            ->whereDoesntHave('excludedComputers', fn ($q) => $q->whereKey($computer->id))
            ->orderBy('id')
            ->get();

        return [
            'policies' => $policies->map(fn (BrowserPolicy $policy) => [
                'policy_id'  => $policy->id,
                'type'       => $policy->type->value,
                'action'     => $policy->action,
                'operations' => $policy->operations(),
            ])->all(),
        ];
    }

    /* ─────────────────────── Result ingestion ────────────────────────── */

    /**
     * Upsert the agent's per-policy/browser reports. Fires a notification
     * when a result *transitions* into a failing state (error or
     * non-compliant) so channels alert once per incident, not per beat.
     *
     * @param list<array{policy_id: int, browser: string, status: string, detail?: ?string, old_value?: ?string, new_value?: ?string}> $results
     */
    public function ingestResults(Computer $computer, array $results): int
    {
        $policyIds = BrowserPolicy::where('project_id', $computer->project_id)->pluck('id')->flip();

        $stored = 0;
        foreach ($results as $result) {
            if (! $policyIds->has((int) $result['policy_id'])) {
                continue; // stale/foreign policy id — ignore
            }

            $previous = BrowserPolicyResult::where([
                'browser_policy_id' => $result['policy_id'],
                'computer_id'       => $computer->id,
                'browser'           => $result['browser'],
            ])->first();

            BrowserPolicyResult::updateOrCreate(
                [
                    'browser_policy_id' => $result['policy_id'],
                    'computer_id'       => $computer->id,
                    'browser'           => $result['browser'],
                ],
                [
                    'status'      => $result['status'],
                    'detail'      => isset($result['detail']) ? mb_substr((string) $result['detail'], 0, 500) : null,
                    'old_value'   => $result['old_value'] ?? null,
                    'new_value'   => $result['new_value'] ?? null,
                    'reported_at' => now(),
                ]
            );
            $stored++;

            $nowFailing = in_array($result['status'], ['error', 'non_compliant'], true);
            $wasFailing = $previous !== null && in_array($previous->status, ['error', 'non_compliant'], true);

            if ($nowFailing && ! $wasFailing) {
                $policy = BrowserPolicy::find($result['policy_id']);
                $this->notifications->notify('browser_policy.failed', "Browser policy failed: {$policy->label()} on {$computer->hostname}", [
                    'computer' => $computer->hostname,
                    'policy'   => $policy->label(),
                    'browser'  => $result['browser'],
                    'status'   => $result['status'],
                    'detail'   => $result['detail'] ?? null,
                ]);
            }
        }

        return $stored;
    }

    /* ─────────────────────── Compliance ──────────────────────────────── */

    /**
     * Per-computer rows for one policy: each targeted machine with its
     * per-browser statuses (or "awaiting agent" when nothing reported yet).
     *
     * @return Collection<int, array{computer: Computer, excluded: bool, browsers: array<string, ?BrowserPolicyResult>, worst: string}>
     */
    public function complianceFor(BrowserPolicy $policy): Collection
    {
        $excluded = $policy->excludedComputers()->pluck('computers.id')->flip();
        $results = $policy->results()->get()->groupBy('computer_id');
        $browsers = array_map(fn ($browser) => $browser->value, $policy->targetBrowsers());

        return $policy->project->computers()->orderBy('hostname')->get()
            ->map(function (Computer $computer) use ($excluded, $results, $browsers) {
                $own = $results->get($computer->id, collect())->keyBy('browser');

                $byBrowser = [];
                foreach ($browsers as $browser) {
                    $byBrowser[$browser] = $own->get($browser);
                }

                return [
                    'computer' => $computer,
                    'excluded' => $excluded->has($computer->id),
                    'browsers' => $byBrowser,
                    'worst'    => $excluded->has($computer->id)
                        ? 'excluded'
                        : self::worstStatus(array_map(fn ($r) => $r?->status, $byBrowser)),
                ];
            });
    }

    /** @return array{target: int, protected: int, non_compliant: int, pending: int, unsupported: int, excluded: int, percent: ?float} */
    public function complianceSummary(BrowserPolicy $policy): array
    {
        $rows = $this->complianceFor($policy);

        $counts = [
            'target'        => $rows->where('excluded', false)->count(),
            'protected'     => $rows->where('worst', 'compliant')->count(),
            'non_compliant' => $rows->whereIn('worst', ['non_compliant', 'error'])->count(),
            'pending'       => $rows->whereIn('worst', ['pending_restart', 'awaiting'])->count(),
            'unsupported'   => $rows->where('worst', 'unsupported')->count(),
            'excluded'      => $rows->where('excluded', true)->count(),
        ];

        $counts['percent'] = $counts['target'] > 0
            ? round($counts['protected'] / $counts['target'] * 100, 1)
            : null;

        return $counts;
    }

    /** Fleet-wide widget: aggregate across every active policy. */
    public function fleetSummary(?int $tenantClientId = null): array
    {
        $policies = BrowserPolicy::with('project')
            ->where('status', 'active')
            ->when($tenantClientId !== null, fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->withTrashed()->where('client_id', $tenantClientId)
            ))
            ->get();

        $totals = ['policies' => $policies->count(), 'target' => 0, 'protected' => 0, 'non_compliant' => 0, 'pending' => 0, 'unsupported' => 0];

        foreach ($policies as $policy) {
            $summary = $this->complianceSummary($policy);
            foreach (['target', 'protected', 'non_compliant', 'pending', 'unsupported'] as $key) {
                $totals[$key] += $summary[$key];
            }
        }

        $totals['percent'] = $totals['target'] > 0
            ? round($totals['protected'] / $totals['target'] * 100, 1)
            : null;

        return $totals;
    }

    /**
     * Collapse per-browser statuses into one machine-level state.
     * not_installed is neutral (nothing to protect); a machine with no
     * reports at all is awaiting its agent.
     */
    public static function worstStatus(array $statuses): string
    {
        $present = array_filter($statuses, fn ($status) => $status !== null);

        if ($present === []) {
            return 'awaiting';
        }

        foreach (['error', 'non_compliant', 'pending_restart', 'unsupported'] as $bad) {
            if (in_array($bad, $present, true)) {
                return $bad === 'error' ? 'non_compliant' : $bad;
            }
        }

        $meaningful = array_filter($present, fn ($status) => $status !== 'not_installed');

        return $meaningful === [] ? 'not_installed' : 'compliant';
    }
}
