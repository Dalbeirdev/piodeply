<?php

namespace App\Livewire\Admin;

use App\Models\WebhookEvent;
use App\Services\WebhookService;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator view of received Stripe webhooks: what came in, what we did with it,
 * and a one-click retry for anything that failed.
 */
class WebhookEvents extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public function mount(): void
    {
        $this->authorize('manage-billing');
    }

    public function retry(int $id, WebhookService $webhooks): void
    {
        $this->authorize('manage-billing');
        $event = WebhookEvent::findOrFail($id);

        try {
            $outcome = $webhooks->handle($event->payload);
            $event->forceFill([
                'status'       => $outcome,
                'processed_at' => now(),
                'error'        => null,
                'attempts'     => $event->attempts + 1,
            ])->save();
            session()->flash('status', "Event {$event->stripe_id} re-processed ({$outcome}).");
        } catch (\Throwable $e) {
            $event->forceFill(['status' => 'failed', 'error' => $e->getMessage(), 'attempts' => $event->attempts + 1])->save();
            session()->flash('status', "Retry failed: {$e->getMessage()}");
        }
    }

    public function render()
    {
        return view('livewire.admin.webhook-events', [
            'events' => WebhookEvent::query()
                ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
                ->latest()
                ->paginate(20),
            'counts' => WebhookEvent::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ])->layout('layouts.app');
    }
}
