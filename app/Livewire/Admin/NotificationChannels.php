<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\NotificationChannel;
use App\Services\NotificationService;
use Illuminate\Validation\Rule;
use Livewire\Component;

class NotificationChannels extends Component
{
    public ?int $editingId = null;

    public string $name = '';

    public string $type = 'email';

    public string $destination = '';

    /** @var list<string> */
    public array $events = [];

    public bool $showForm = false;

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);
    }

    public function create(): void
    {
        $this->authorizeManage();
        $this->reset(['editingId', 'name', 'destination', 'events']);
        $this->type = 'email';
        $this->showForm = true;
    }

    public function edit(int $channelId): void
    {
        $this->authorizeManage();
        $channel = NotificationChannel::findOrFail($channelId);

        $this->editingId = $channel->id;
        $this->name = $channel->name;
        $this->type = $channel->type;
        $this->destination = $channel->destination;
        $this->events = $channel->events;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'name'        => ['required', 'string', 'max:100'],
            'type'        => ['required', Rule::in([NotificationChannel::TYPE_EMAIL, NotificationChannel::TYPE_WEBHOOK])],
            // Webhook URLs (Teams, Azure Logic Apps) can run to hundreds of
            // characters; the column now holds 2048, and the validation must
            // not promise more room than the column has ever again.
            'destination' => ['required', 'string', 'max:2048',
                $this->type === NotificationChannel::TYPE_EMAIL ? 'email' : 'url'],
            'events'      => ['required', 'array', 'min:1'],
            'events.*'    => [Rule::in(array_keys(NotificationChannel::EVENTS))],
        ], [
            'destination.email' => 'Email channels need a valid email address.',
            'destination.url'   => 'Webhook channels need a valid URL (https://…).',
            'events.required'   => 'Pick at least one event.',
        ]);

        $validated['events'] = array_values($validated['events']);

        if ($this->editingId !== null) {
            NotificationChannel::findOrFail($this->editingId)->update($validated);
            session()->flash('status', 'Channel saved.');
        } else {
            NotificationChannel::create($validated + ['created_by' => auth()->id()]);
            session()->flash('status', 'Channel created.');
        }

        $this->showForm = false;
    }

    public function toggle(int $channelId): void
    {
        $this->authorizeManage();
        $channel = NotificationChannel::findOrFail($channelId);
        $channel->update(['is_active' => ! $channel->is_active]);
    }

    public function sendTest(int $channelId, NotificationService $service): void
    {
        $this->authorizeManage();
        $channel = NotificationChannel::findOrFail($channelId);

        $ok = $service->deliver($channel, 'test', 'Test notification from PioDeploy', [
            'sent_by' => auth()->user()->name,
            'channel' => $channel->name,
        ]);

        session()->flash('status', $ok
            ? "Test sent to “{$channel->name}”."
            : "Test failed for “{$channel->name}” — " . ($channel->fresh()->last_error ?? 'unknown error'));
    }

    public function delete(int $channelId): void
    {
        $this->authorizeManage();
        NotificationChannel::findOrFail($channelId)->delete();
        session()->flash('status', 'Channel deleted.');
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.admin.notification-channels', [
            'channels'  => NotificationChannel::orderBy('name')->get(),
            'allEvents' => NotificationChannel::EVENTS,
        ])->layout('layouts.app');
    }
}
