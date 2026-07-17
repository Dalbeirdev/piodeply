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
    public const PLAIN_KEYS = ['provider', 'host', 'port', 'username', 'scheme', 'from_address', 'from_name'];

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
     * What to actually do about an SMTP failure.
     *
     * The provider's own words are precise and useless unless you already
     * speak SMTP: "553 5.7.1" is exactly right and tells an operator nothing.
     * This sits above the verbatim error, never instead of it — a guess that
     * replaced the evidence would be worse than no guess.
     */
    public function hintFor(string $error): ?string
    {
        $e = mb_strtolower($error);

        return match (true) {
            // Authenticated fine, then tried to send as someone else.
            str_contains($e, '553') || str_contains($e, '5.7.1') || str_contains($e, 'sender address rejected')
                => 'The provider accepted your login but refused the From address. It usually has to be the '
                 . 'same mailbox you authenticated as, or another address verified with them.',

            str_contains($e, '535') || str_contains($e, 'authentication failed') || str_contains($e, 'auth')
                => 'The username or password was rejected. Several providers want something other than your '
                 . 'account password here — an App Password, an API key, or a mailbox-specific password.',

            str_contains($e, 'getaddrinfo') || str_contains($e, 'name or service not known') || str_contains($e, 'could not be resolved')
                => 'The host does not exist. Check it for a typo, and that it is the right one for your plan.',

            str_contains($e, 'connection refused') || str_contains($e, 'connection could not be established')
                => 'Nothing answered on that host and port. Try the other pairing — 587 with STARTTLS, or 465 '
                 . 'with SSL/TLS — since providers differ, and some networks block one of them outright.',

            str_contains($e, 'timed out') || str_contains($e, 'timeout')
                => 'The connection hung rather than being refused, which usually means a firewall is dropping '
                 . 'outbound mail on this port.',

            str_contains($e, 'ssl') || str_contains($e, 'tls') || str_contains($e, 'certificate')
                => 'The encryption did not agree. 465 expects SSL/TLS and 587 expects STARTTLS — a mismatched '
                 . 'pair fails exactly like this.',

            str_contains($e, '550') || str_contains($e, 'relay')
                => 'The provider would not relay this message. Usually the sending domain is not verified with '
                 . 'them, or the mailbox is not allowed to send externally.',

            default => null,
        };
    }

    /**
     * Push an explicit set of values over the config, without saving them.
     * Lets the operator test a configuration before committing it — the whole
     * point of a test button is to try before you trust.
     *
     * @param array{host?:?string, port?:?string, username?:?string, scheme?:?string, from_address?:?string, from_name?:?string} $values
     */
    public function applyRuntime(array $values, ?string $password): void
    {
        config([
            'mail.default'                => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host'      => $values['host'] ?? null,
            'mail.mailers.smtp.port'      => (int) (($values['port'] ?? null) ?: 587),
            'mail.mailers.smtp.username'  => ($values['username'] ?? null) ?: null,
            // A typed password wins; a blank field falls back to what is
            // stored, so testing an unchanged password after editing the host
            // does not force the operator to retype the secret.
            'mail.mailers.smtp.password'  => ($password !== null && trim($password) !== '') ? trim($password) : $this->password(),
            'mail.mailers.smtp.scheme'    => ($values['scheme'] ?? null) ?: null,
        ]);

        if (trim((string) ($values['from_address'] ?? '')) !== '') {
            config(['mail.from.address' => $values['from_address']]);
        }
        if (trim((string) ($values['from_name'] ?? '')) !== '') {
            config(['mail.from.name' => $values['from_name']]);
        }
    }

    /**
     * Send a real message with the given settings (or, if none passed, the
     * stored ones). Returns the provider's complaint verbatim on failure —
     * paraphrasing an SMTP error helps nobody.
     *
     * @param array<string, string|null>|null $override
     */
    public function sendTest(string $to, ?array $override = null, ?string $password = null): ?string
    {
        try {
            $override !== null ? $this->applyRuntime($override, $password) : $this->apply();

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
