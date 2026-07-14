<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Livewire\Concerns\WithCompactPagination;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail: every logged action (RBAC changes, policy edits,
 * logins, impersonation, entity changes) in one filterable stream.
 */
class ActivityIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    public string $logFilter = '';

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'logFilter'], true)) {
            $this->resetPage();
        }
    }

    public function render()
    {
        abort_unless(auth()->user()->can(Permission::ActivityView->value), 403);

        $activities = Activity::query()
            ->with('causer')
            ->when($this->logFilter !== '', fn ($q) => $q->where('log_name', $this->logFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('description', 'like', "%{$this->search}%")
                ->orWhereHasMorph('causer', [\App\Models\User::class], fn ($c) => $c
                    ->where('name', 'like', "%{$this->search}%"))))
            ->latest()
            ->paginate(25);

        return view('livewire.admin.activity-index', [
            'activities' => $activities,
            'logNames'   => Activity::query()->distinct()->orderBy('log_name')->pluck('log_name'),
        ])->layout('layouts.app');
    }
}
