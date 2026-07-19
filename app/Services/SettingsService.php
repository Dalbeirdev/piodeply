<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime-tunable platform settings, stored in the database and cached.
 * Every key has a config/hardcoded fallback, so a missing row — or a
 * missing table during early migrations — never breaks anything.
 */
class SettingsService
{
    private const CACHE_KEY = 'app-settings';

    /** Known keys and their default values (resolved lazily). */
    public static function defaults(): array
    {
        return [
            'branding.company_name'             => config('app.name', 'PioDeploy'),
            'agent.online_threshold_seconds'    => (int) config('piodeploy.agent.online_threshold_seconds', 300),
            'notifications.offline_after_minutes' => (int) config('piodeploy.notifications.offline_after_minutes', 60),
            'deployments.default_max_attempts'  => 3,
            'policies.failure_backoff_hours'    => 23,
            'retention.activity_days'           => 180,
            // 'off' | 'staff' (everyone but client-portal users) | 'all'
            'security.require_two_factor'       => 'off',
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $default ??= self::defaults()[$key] ?? null;

        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        try {
            return Cache::rememberForever(
                self::CACHE_KEY,
                fn () => Setting::pluck('value', 'key')->all()
            );
        } catch (\Throwable) {
            return []; // settings table not migrated yet — defaults apply
        }
    }
}
