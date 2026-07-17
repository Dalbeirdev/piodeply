<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SMTP settings, editable in the portal rather than over SSH.
 *
 * They live in the settings table and are pushed into the mail config at
 * boot, so changing a provider is a form rather than an .env edit, a
 * config:cache and a redeploy. The password is encrypted at rest and never
 * leaves the server: the form takes a new one or leaves the stored one alone.
 *
 * .env still wins when nothing is configured here, so an install that already
 * had working mail keeps it.
 */
class MailSettingsService
{
    /** Everything except the password, which is handled separately. */
    public const PLAIN_KEYS = ['host', 'port', 'username', 'scheme', 'from_address', 'from_name'];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings->get("mail.{$key}", $default);
    }

    /** Configured here at all? If not, .env is left in charge. */
    public function configured(): bool
    {
        return trim((string) $this->get('host')) !== '';
    }

    public function hasPassword(): bool
    {
        return trim((string) $this->settings->get('mail.password')) !== '';
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  string|null  $password  Null leaves the stored one untouched —
     *         an empty form field means "unchanged", not "clear it".
     */
    public function save(array $values, ?string $password = null): void
    {
        foreach (self::PLAIN_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $this->settings->set("mail.{$key}", $values[$key] === null ? '' : trim((string) $values[$key]));
            }
        }

        if ($password !== null && trim($password) !== '') {
            $this->settings->set('mail.password', Crypt::encryptString(trim($password)));
        }
    }

    public function forgetPassword(): void
    {
        $this->settings->set('mail.password', '');
    }

    /** Decrypted only here, only to hand to the transport. */
    public function password(): ?string
    {
        $stored = (string) $this->settings->get('mail.password');

        if (trim($stored) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable $e) {
            // A rotated APP_KEY makes every stored secret unreadable. Say so
            // rather than authenticate with gibberish and blame the provider.
            Log::warning('Stored SMTP password could not be decrypted — has APP_KEY changed?');

            return null;
        }
    }

    /**
     * Push the saved settings over the config for this request. Called at
     * boot; does nothing when nothing is saved, so .env still applies.
     */
    public function apply(): void
    {
        if (! $this->configured()) {
            return;
        }

        config([
            'mail.default'                => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host'      => $this->get('host'),
            'mail.mailers.smtp.port'      => (int) ($this->get('port') ?: 587),
            'mail.mailers.smtp.username'  => $this->get('username') ?: null,
            'mail.mailers.smtp.password'  => $this->password(),
            // Laravel 12 takes a scheme (smtp / smtps), not an encryption name.
            'mail.mailers.smtp.scheme'    => $this->get('scheme') ?: null,
        ]);

        if (trim((string) $this->get('from_address')) !== '') {
            config(['mail.from.address' => $this->get('from_address')]);
        }

        if (trim((string) $this->get('from_name')) !== '') {
            config(['mail.from.name' => $this->get('from_name')]);
        }
    }

    /**
     * Send a real message with the current settings. Returns the provider's
     * complaint verbatim on failure — paraphrasing an SMTP error helps nobody.
     */
    public function sendTest(string $to): ?string
    {
        try {
            $this->apply();

            Mail::raw(
                "This is a test from PioDeploy.\n\nIf you are reading this, outgoing mail works: "
                ."deployment failures, offline alerts and website enquiries can reach you.",
                fn ($message) => $message->to($to)->subject('PioDeploy test email')
            );

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
