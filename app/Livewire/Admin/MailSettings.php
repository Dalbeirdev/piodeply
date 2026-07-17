<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Services\MailSettingsService;
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

        $this->host         = (string) $mail->get('host', '');
        $this->port         = (string) ($mail->get('port') ?: '587');
        $this->username     = (string) $mail->get('username', '');
        $this->scheme       = (string) ($mail->get('scheme') ?: 'tls');
        $this->from_address = (string) ($mail->get('from_address') ?: config('mail.from.address'));
        $this->from_name    = (string) ($mail->get('from_name') ?: config('mail.from.name'));
        $this->testTo       = (string) (auth()->user()->email ?? '');
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
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

        return view('livewire.admin.mail-settings', [
            'hasPassword' => $mail->hasPassword(),
            'usingEnv'    => ! $mail->configured(),
            'envHost'     => config('mail.mailers.smtp.host'),
        ])->layout('layouts.app');
    }
}
