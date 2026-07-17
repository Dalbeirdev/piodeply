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

    /** The row expanded to full detail; opening it marks the enquiry read. */
    public ?int $viewingId = null;

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'type', 'openOnly'], true)) {
            $this->resetPage();
        }
    }

    /** Expand a row (or collapse it), marking it read the first time. */
    public function view(int $leadId): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        if ($this->viewingId === $leadId) {
            $this->viewingId = null;

            return;
        }

        $this->viewingId = $leadId;

        $lead = Lead::findOrFail($leadId);
        if ($lead->read_at === null) {
            $lead->forceFill(['read_at' => now()])->save();
        }
    }

    public function toggleRead(int $leadId): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $lead = Lead::findOrFail($leadId);
        $lead->forceFill(['read_at' => $lead->read_at === null ? now() : null])->save();
    }

    public function markHandled(int $leadId): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $lead = Lead::findOrFail($leadId);
        // Handling something implies you have read it.
        $lead->forceFill([
            'handled_at' => $lead->handled_at === null ? now() : null,
            'read_at'    => $lead->read_at ?? now(),
        ])->save();
    }

    public function delete(int $leadId): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        Lead::findOrFail($leadId)->delete();
        if ($this->viewingId === $leadId) {
            $this->viewingId = null;
        }
        session()->flash('status', 'Enquiry deleted.');
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
            'openCount'   => Lead::whereNull('handled_at')->count(),
            'unreadCount' => Lead::whereNull('read_at')->count(),
        ])->layout('layouts.app');
    }
}
