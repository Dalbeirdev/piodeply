<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Services\MailSettingsService;
use App\Support\MailProviders;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * SMTP, editable here rather than over SSH.
 *
 * The password is write-only. Livewire serialises public properties into the
 * page and posts them on every update, so the stored one is never loaded into
 * $password: blank means "leave it alone", and only a typed value is saved.
 */
class MailSettings extends Component
{
    /** A key from MailProviders, or 'custom'. */
    public string $provider = MailProviders::CUSTOM;

    /** Reveal the server details a preset already knows. */
    public bool $advanced = false;

    public string $host = '';

    public string $port = '587';

    public string $username = '';

    /** Never populated from storage — only ever a new value on its way in. */
    public string $password = '';

    public string $scheme = 'tls';

    public string $from_address = '';

    public string $from_name = '';

    public string $testTo = '';

    public ?string $testError = null;

    /** What to do about it, when the error is one we recognise. */
    public ?string $testHint = null;

    public bool $testSent = false;

    public function mount(MailSettingsService $mail): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $this->provider     = (string) ($mail->get('provider') ?: MailProviders::CUSTOM);
        $this->host         = (string) $mail->get('host', '');
        $this->port         = (string) ($mail->get('port') ?: '587');
        $this->username     = (string) $mail->get('username', '');
        // Stored as a transport scheme (smtp/smtps/''); the form works in
        // tls/ssl/none. Converting back is what was missing — the raw stored
        // value is not one of the dropdown's options, so validation rejected
        // every save after the first, with no visible error.
        $this->scheme       = self::schemeToForm((string) $mail->get('scheme'));
        $this->from_address = (string) ($mail->get('from_address') ?: config('mail.from.address'));
        $this->from_name    = (string) ($mail->get('from_name') ?: config('mail.from.name'));
        $this->testTo       = (string) (auth()->user()->email ?? '');
    }

    /**
     * Picking a provider fills in what it already determines. The host stays
     * editable behind "advanced" — a preset is a shortcut, not a cage.
     */
    public function updatedProvider(string $value): void
    {
        $preset = MailProviders::get($value);

        if ($preset['host'] !== null) {
            $this->host = $preset['host'];
        }

        $this->port     = (string) $preset['port'];
        $this->scheme   = $preset['scheme'];
        $this->advanced = false;
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'provider'     => ['required', 'string', Rule::in(MailProviders::keys())],
            'host'         => ['required', 'string', 'max:190'],
            'port'         => ['required', 'integer', 'between:1,65535'],
            'username'     => ['nullable', 'string', 'max:190'],
            'password'     => ['nullable', 'string', 'max:190'],
            'scheme'       => ['required', 'in:tls,ssl,none'],
            'from_address' => ['required', 'email', 'max:190'],
            'from_name'    => ['required', 'string', 'max:120'],
        ];
    }

    /** Dropdown value -> transport scheme stored and handed to the mailer. */
    public static function formToScheme(string $form): string
    {
        return match ($form) {
            'ssl'  => 'smtps',
            'none' => '',
            default => 'smtp',
        };
    }

    /** Transport scheme -> dropdown value, for reading a saved setting back. */
    public static function schemeToForm(string $scheme): string
    {
        return match ($scheme) {
            'smtps' => 'ssl',
            ''      => 'none',
            default => 'tls',
        };
    }

    public function save(MailSettingsService $mail): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $validated = $this->validate($this->rules(), [], [
            'from_address' => 'from address',
            'from_name'    => 'from name',
        ]);

        $mail->save([
            'provider'     => $validated['provider'],
            'host'         => $validated['host'],
            'port'         => $validated['port'],
            'username'     => $validated['username'] ?? '',
            'scheme'       => self::formToScheme($validated['scheme']),
            'from_address' => $validated['from_address'],
            'from_name'    => $validated['from_name'],
        ], $validated['password'] ?? null);

        $this->password = '';
        $this->testSent = false;
        $this->testError = null;
        $this->testHint = null;

        session()->flash('status', 'Email settings saved. Send a test to confirm they work.');
    }

    public function clearPassword(MailSettingsService $mail): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $mail->forgetPassword();
        session()->flash('status', 'Stored password removed.');
    }

    public function sendTest(MailSettingsService $mail): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $this->validate(['testTo' => ['required', 'email']]);

        // A real send that hits an external server, so it is rate limited: a
        // settings-manager should not be able to loop it as a relay.
        $key = 'mail-test:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->testSent = false;
            $this->testHint = null;
            $this->testError = 'Too many test emails just now — wait a minute and try again.';

            return;
        }
        RateLimiter::hit($key, 60);

        // Test what is on screen, saved or not — the promise the page makes,
        // and the point of testing before you commit. scheme maps the form's
        // tls/ssl/none to the transport's smtp/smtps/plain, as save() does.
        $override = [
            'host'         => $this->host,
            'port'         => $this->port,
            'username'     => $this->username,
            'scheme'       => self::formToScheme($this->scheme),
            'from_address' => $this->from_address,
            'from_name'    => $this->from_name,
        ];

        $this->testError = $mail->sendTest($this->testTo, $override, $this->password);
        $this->testSent  = $this->testError === null;
        $this->testHint  = $this->testError === null ? null : $mail->hintFor($this->testError);
    }

    public function render(MailSettingsService $mail)
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $preset = MailProviders::get($this->provider);

        return view('livewire.admin.mail-settings', [
            'hasPassword' => $mail->hasPassword(),
            'usingEnv'    => ! $mail->configured(),
            'envHost'     => config('mail.mailers.smtp.host'),
            'providers'   => MailProviders::all(),
            'preset'      => $preset,
            // A preset that pins the host has nothing left to ask about the
            // server, so those fields only get in the way.
            'showServerFields' => $this->advanced || $preset['host'] === null,
        ])->layout('layouts.app');
    }
}
