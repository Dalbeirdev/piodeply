<?php

namespace App\Support;

/**
 * The handful of SMTP providers an MSP actually uses, and what each one
 * quietly expects.
 *
 * Every one of these has a gotcha that costs an hour if you do not know it —
 * SendGrid's username is the literal word "apikey", Postmark wants the same
 * token twice, Gmail refuses your account password outright. Knowing the host
 * is the easy part; the hints are the point.
 */
final class MailProviders
{
    public const CUSTOM = 'custom';

    /**
     * @return array<string, array{
     *     label: string, host: ?string, port: int, scheme: string,
     *     username_hint: string, password_hint: string, warning: ?string
     * }>
     */
    public static function all(): array
    {
        return [
            'gmail' => [
                'label'         => 'Gmail / Google Workspace',
                'host'          => 'smtp.gmail.com',
                'port'          => 587,
                'scheme'        => 'tls',
                'username_hint' => 'Your full Gmail address',
                'password_hint' => 'A 16-character App Password — not your account password.',
                'warning'       => 'Google only issues App Passwords once 2-Step Verification is on, and rejects your normal password outright. Create one at myaccount.google.com → Security → App passwords.',
            ],
            'microsoft365' => [
                'label'         => 'Microsoft 365 / Outlook',
                'host'          => 'smtp.office365.com',
                'port'          => 587,
                'scheme'        => 'tls',
                'username_hint' => 'The full mailbox address you are sending from',
                'password_hint' => 'That mailbox’s password',
                'warning'       => 'Microsoft turns SMTP AUTH off for new tenants and is retiring password-based SMTP. It must be enabled per mailbox in the admin centre, and a tenant with security defaults on will refuse regardless. If this fails with "SmtpClientAuthentication is disabled", that is why.',
            ],
            'postmark' => [
                'label'         => 'Postmark',
                'host'          => 'smtp.postmarkapp.com',
                'port'          => 587,
                'scheme'        => 'tls',
                'username_hint' => 'Your Server API token',
                'password_hint' => 'The same Server API token again',
                'warning'       => 'Username and password are both the Server API token — the same value in both boxes. Not a mistake.',
            ],
            'sendgrid' => [
                'label'         => 'SendGrid',
                'host'          => 'smtp.sendgrid.net',
                'port'          => 587,
                'scheme'        => 'tls',
                'username_hint' => 'The literal word: apikey',
                'password_hint' => 'Your API key (starts SG.)',
                'warning'       => 'The username is literally "apikey" — not your account name, not your email. Every SendGrid account uses the same username.',
            ],
            'brevo' => [
                'label'         => 'Brevo (formerly Sendinblue)',
                'host'          => 'smtp-relay.brevo.com',
                'port'          => 587,
                'scheme'        => 'tls',
                'username_hint' => 'Your Brevo login email',
                'password_hint' => 'An SMTP key from Brevo — not your account password',
                'warning'       => 'The SMTP key is generated separately under SMTP & API → SMTP. Your login password will not work.',
            ],
            'mailgun' => [
                'label'         => 'Mailgun',
                'host'          => 'smtp.mailgun.org',
                'port'          => 587,
                'scheme'        => 'tls',
                'username_hint' => 'postmaster@your-domain (from Mailgun’s dashboard)',
                'password_hint' => 'That SMTP user’s password',
                'warning'       => 'EU accounts use smtp.eu.mailgun.org instead — pick "Other" and set the host yourself if your domain is in the EU region.',
            ],
            'ses' => [
                // Region-specific, so the host has to be asked for.
                'label'         => 'Amazon SES',
                'host'          => null,
                'port'          => 587,
                'scheme'        => 'tls',
                'username_hint' => 'Your SES SMTP username (not an AWS access key ID)',
                'password_hint' => 'Your SES SMTP password',
                'warning'       => 'The host is region-specific — email-smtp.eu-west-1.amazonaws.com, and so on. SES SMTP credentials are generated in the SES console and are not your IAM keys.',
            ],
            self::CUSTOM => [
                'label'         => 'Other / custom SMTP',
                'host'          => null,
                'port'          => 587,
                'scheme'        => 'tls',
                'username_hint' => '',
                'password_hint' => '',
                'warning'       => null,
            ],
        ];
    }

    /** @return array{label: string, host: ?string, port: int, scheme: string, username_hint: string, password_hint: string, warning: ?string} */
    public static function get(?string $key): array
    {
        return self::all()[$key] ?? self::all()[self::CUSTOM];
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::all());
    }

    /** Presets that pin the host — the fields the operator need not see. */
    public static function pinsHost(?string $key): bool
    {
        return self::get($key)['host'] !== null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
