<?php

namespace App\Livewire\Team;

use Livewire\Component;

/**
 * A client owner's branding page: what THEIR users see. Portal name shows
 * in the sidebar for everyone in this client; tray name and visibility
 * govern the agent's tray icon on their machines (delivered at the next
 * agent check-in). Empty names fall back to the platform defaults.
 */
class Branding extends Component
{
    public string $portal_name = '';

    public string $tray_name = '';

    public bool $show_tray_icon = true;

    public function mount(): void
    {
        $this->assertOwner();

        $client = auth()->user()->client;
        $this->portal_name = (string) $client->portal_name;
        $this->tray_name = (string) $client->tray_name;
        $this->show_tray_icon = (bool) $client->show_tray_icon;
    }

    private function assertOwner(): void
    {
        abort_if(auth()->user()->tenantClientId() === null, 403, 'Branding is for client accounts.');
        abort_unless(auth()->user()->isClientOwner(), 403, 'Only account owners change branding.');
    }

    public function save(): void
    {
        $this->assertOwner();

        $validated = $this->validate([
            'portal_name'    => ['nullable', 'string', 'max:60'],
            'tray_name'      => ['nullable', 'string', 'max:60'],
            'show_tray_icon' => ['boolean'],
        ]);

        auth()->user()->client->update([
            'portal_name'    => trim($validated['portal_name']) ?: null,
            'tray_name'      => trim($validated['tray_name']) ?: null,
            'show_tray_icon' => $validated['show_tray_icon'],
        ]);

        activity('branding')
            ->causedBy(auth()->user())
            ->withProperties($validated)
            ->log('client_branding_saved');

        session()->flash('status', 'Branding saved. Machines pick up tray changes at their next check-in (about a minute while online).');
    }

    public function render()
    {
        return view('livewire.team.branding')->layout('layouts.app');
    }
}
