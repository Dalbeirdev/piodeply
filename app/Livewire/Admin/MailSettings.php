<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Services\MailSettingsService;
use App\Support\MailProviders;
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

    public bool $testSent = false;

    public function mount(MailSettingsService $mail): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);

        $this->provider     = (string) ($mail->get('provider') ?: MailProviders::CUSTOM);
        $this->host         = (string) $mail->get('host', '');
        $this->port         = (string) ($mail->get('port') ?: '587');
        $this->username     = (string) $mail->get('username', '');
        $this->scheme       = (string) ($mail->get('scheme') ?: 'tls');
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
            // Stored as a scheme; "none" means plain, which some internal
            // relays legitimately want.
            'scheme'       => $validated['scheme'] === 'none' ? '' : ($validated['scheme'] === 'ssl' ? 'smtps' : 'smtp'),
            'from_address' => $validated['from_address'],
            'from_name'    => $validated['from_name'],
        ], $validated['password'] ?? null);

        $this->password = '';
        $this->testSent = false;
        $this->testError = null;

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

        $this->testError = $mail->sendTest($this->testTo);
        $this->testSent  = $this->testError === null;
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
