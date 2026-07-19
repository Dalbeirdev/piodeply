<?php

namespace App\Livewire\BrowserPolicies;

use App\Models\BrowserPolicy;
use App\Services\BrowserPolicyService;
use Livewire\Component;

class BrowserPolicyShow extends Component
{
    public BrowserPolicy $policy;

    public string $statusFilter = '';

    public function mount(BrowserPolicy $policy): void
    {
        $this->authorize('view', $policy);
        $this->policy = $policy->load(['project.client', 'creator']);
    }

    public function filterBy(string $status): void
    {
        $this->statusFilter = $this->statusFilter === $status ? '' : $status;
    }

    public function toggleExclusion(int $computerId): void
    {
        $this->authorize('update', $this->policy);

        $this->policy->excludedComputers()->toggle([$computerId]);

        activity('browser-policies')
            ->causedBy(auth()->user())
            ->performedOn($this->policy)
            ->withProperties(['computer_id' => $computerId])
            ->log('exclusion_toggled');
    }

    public function export(BrowserPolicyService $service)
    {
        abort_unless(auth()->user()->can(\App\Enums\Permission::ReportsExport->value), 403);

        $rows = $service->complianceFor($this->policy);
        $browsers = array_map(fn ($browser) => $browser->value, $this->policy->targetBrowsers());

        $csv = 'Computer,Overall,' . implode(',', array_map('ucfirst', $browsers)) . ",Last reported\n";
        foreach ($rows as $row) {
            $lastReported = collect($row['browsers'])->filter()->max('reported_at');
            $csv .= implode(',', array_map(
                fn ($value) => '"' . str_replace('"', '""', (string) $value) . '"',
                [
                    $row['computer']->hostname,
                    $row['worst'],
                    ...array_map(fn ($browser) => $row['browsers'][$browser]?->status ?? '—', $browsers),
                    $lastReported?->format('Y-m-d H:i') ?? 'never',
                ]
            )) . "\n";
        }

        return response()->streamDownload(
            fn () => print($csv),
            'piodeploy-browser-policy-' . $this->policy->id . '-' . now()->format('Ymd-His') . '.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function render(BrowserPolicyService $service)
    {
        $rows = $service->complianceFor($this->policy);

        // A shared (all/group) policy can be visible to a tenant, but the
        // machines listed must still be their own only.
        $tenantId = auth()->user()->tenantClientId();
        if ($tenantId !== null) {
            $rows = $rows->filter(fn (array $row) => $row['computer']->project()->withTrashed()->first()?->client_id === $tenantId)->values();
        }

        $filtered = match ($this->statusFilter) {
            '' => $rows,
            'non_compliant' => $rows->whereIn('worst', ['non_compliant', 'error']),
            default => $rows->where('worst', $this->statusFilter),
        };

        return view('livewire.browser-policies.browser-policy-show', [
            'summary'  => $service->complianceSummary($this->policy),
            'rows'     => $filtered->values(),
            'browsers' => array_map(fn ($browser) => $browser->value, $this->policy->targetBrowsers()),
            'history'  => \Spatie\Activitylog\Models\Activity::query()
                ->where('subject_type', BrowserPolicy::class)
                ->where('subject_id', $this->policy->id)
                ->with('causer')
                ->latest()
                ->limit(10)
                ->get(),
        ])->layout('layouts.app');
    }
}
