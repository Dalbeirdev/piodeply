<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Livewire\Concerns\WithCompactPagination;
use App\Models\Lead;
use Livewire\Component;

/**
 * Everything the website sent us.
 *
 * The form always stored these, but nothing showed them: a lead was only
 * ever visible as a notification, so an unconfigured mailer or a missing
 * channel turned a real enquiry into silence. The record is the product;
 * the email is a courtesy.
 */
class LeadsIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    /** all | contact | access_request */
    public string $type = '';

    /** Open work first — a handled lead is history. */
    public bool $openOnly = true;

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'type', 'openOnly'], true)) {
            $this->resetPage();
        }
    }

    public function markHandled(int $leadId): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $lead = Lead::findOrFail($leadId);
        $lead->forceFill(['handled_at' => $lead->handled_at === null ? now() : null])->save();
    }

    public function render()
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        return view('livewire.admin.leads-index', [
            'leads' => Lead::query()
                ->when($this->openOnly, fn ($q) => $q->whereNull('handled_at'))
                ->when($this->type !== '', fn ($q) => $q->where('type', $this->type))
                ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
                    ->orWhere('company', 'like', "%{$this->search}%")))
                ->latest()
                ->paginate(20),
            'openCount' => Lead::whereNull('handled_at')->count(),
        ])->layout('layouts.app');
    }
}
