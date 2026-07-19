<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Services\SettingsService;
use Livewire\Component;

class SettingsPage extends Component
{
    public string $company_name = '';

    public int $online_threshold_seconds = 300;

    public int $offline_after_minutes = 60;

    public int $default_max_attempts = 3;

    public int $failure_backoff_hours = 23;

    public int $activity_retention_days = 180;

    public string $require_two_factor = 'off';

    public function mount(SettingsService $settings): void
    {
        $this->authorizeManage();

        $this->company_name = (string) $settings->get('branding.company_name');
        $this->online_threshold_seconds = (int) $settings->get('agent.online_threshold_seconds');
        $this->offline_after_minutes = (int) $settings->get('notifications.offline_after_minutes');
        $this->default_max_attempts = (int) $settings->get('deployments.default_max_attempts');
        $this->failure_backoff_hours = (int) $settings->get('policies.failure_backoff_hours');
        $this->activity_retention_days = (int) $settings->get('retention.activity_days');
        $this->require_two_factor = (string) $settings->get('security.require_two_factor');
    }

    public function save(SettingsService $settings): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'company_name'             => ['required', 'string', 'max:100'],
            'online_threshold_seconds' => ['required', 'integer', 'between:60,3600'],
            'offline_after_minutes'    => ['required', 'integer', 'between:5,10080'],
            'default_max_attempts'     => ['required', 'integer', 'between:1,10'],
            'failure_backoff_hours'    => ['required', 'integer', 'between:1,168'],
            'activity_retention_days'  => ['required', 'integer', 'between:7,3650'],
            'require_two_factor'       => ['required', 'in:off,staff,all'],
        ]);

        $map = [
            'branding.company_name'                => $validated['company_name'],
            'agent.online_threshold_seconds'       => (int) $validated['online_threshold_seconds'],
            'notifications.offline_after_minutes'  => (int) $validated['offline_after_minutes'],
            'deployments.default_max_attempts'     => (int) $validated['default_max_attempts'],
            'policies.failure_backoff_hours'       => (int) $validated['failure_backoff_hours'],
            'retention.activity_days'              => (int) $validated['activity_retention_days'],
            'security.require_two_factor'          => $validated['require_two_factor'],
        ];

        foreach ($map as $key => $value) {
            $settings->set($key, $value);
        }

        activity('settings')
            ->causedBy(auth()->user())
            ->withProperties($map)
            ->log('settings_saved');

        session()->flash('status', 'Settings saved — they apply immediately.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.admin.settings-page')->layout('layouts.app');
    }
}
