<?php

namespace App\Services;

use App\Models\Computer;

/**
 * Turns the agent's raw readiness self-checks into something an operator can
 * act on. Every failure mode here is one this fleet actually hit — a machine
 * that fails a check cannot sync or deploy software reliably, so the portal
 * says which check, in plain terms, with the fix.
 */
class ReadinessService
{
    /**
     * A known check's human title and, when it fails, what to do about it.
     *
     * @return array{title: string, fix: string}
     */
    public function describe(string $key, ?string $detail): array
    {
        return match ($key) {
            'winget' => [
                'title' => 'Windows Package Manager (winget)',
                'fix'   => 'winget is not usable by the agent’s SYSTEM account on this machine. '
                         . 'Repair “App Installer” from the Microsoft Store, and make sure winget runs at all: '
                         . 'open an elevated PowerShell and try “winget --version”.',
            ],
            'winget_scan' => [
                'title' => 'Software inventory scan',
                'fix'   => 'winget ran but listed nothing, so the catalogue cannot match anything on this machine. '
                         . 'Usually the same cause as the winget check above; upgrade the agent if it is below 1.3.1.',
            ],
            'vcredist' => [
                'title' => 'Visual C++ Redistributable',
                'fix'   => 'Installers fail to launch without it (exit -1073741515 / 0xC0000135), common on a fresh VM. '
                         . 'Install it on the machine: winget install Microsoft.VCRedist.2015+.x64',
            ],
            default => [
                'title' => ucfirst(str_replace('_', ' ', $key)),
                'fix'   => $detail ?? 'See the machine’s agent log for details.',
            ],
        };
    }

    /**
     * The failing checks for a machine, each with its human title, the agent's
     * own detail, and the fix. Empty when the machine is ready — or has not
     * reported yet.
     *
     * @return list<array{key: string, title: string, detail: ?string, fix: string}>
     */
    public function issues(Computer $computer): array
    {
        $checks = $computer->environment ?? [];

        return collect($checks)
            ->filter(fn ($check) => ($check['ok'] ?? true) === false)
            ->map(function ($check) {
                $described = $this->describe($check['key'] ?? '', $check['detail'] ?? null);

                return [
                    'key'    => $check['key'] ?? '',
                    'title'  => $described['title'],
                    'detail' => $check['detail'] ?? null,
                    'fix'    => $described['fix'],
                ];
            })
            ->values()
            ->all();
    }

    public function isReady(Computer $computer): bool
    {
        return $this->issues($computer) === [];
    }

    /** How many machines cannot currently sync or deploy software. */
    public function notReadyCount(?int $clientId = null): int
    {
        return Computer::query()
            ->whereNotNull('environment')
            ->when($clientId !== null, fn ($q) => $q->whereHas(
                'project',
                fn ($p) => $p->withTrashed()->where('client_id', $clientId)
            ))
            ->get()
            ->filter(fn (Computer $c) => ! $this->isReady($c))
            ->count();
    }
}
