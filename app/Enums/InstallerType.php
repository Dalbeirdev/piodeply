<?php

namespace App\Enums;

enum InstallerType: string
{
    // Capability matrix below (supportsRollback / supportsUninstall / supports)
    // is the single source of truth the UI, the queue guard and the policy
    // engine all consult, so "which application can roll back" is answered in
    // exactly one place.

    case Msi = 'msi';
    case Exe = 'exe';
    case Zip = 'zip';
    case Msix = 'msix';
    case Winget = 'winget';
    case Choco = 'choco';
    case Portable = 'portable';
    case PowerShell = 'powershell';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Msi => 'MSI',
            self::Exe => 'EXE',
            self::Zip => 'ZIP',
            self::Msix => 'MSIX',
            self::Winget => 'winget',
            self::Choco => 'Chocolatey',
            self::Portable => 'Portable',
            self::PowerShell => 'PowerShell',
        };
    }

    /**
     * Types the agent must download and verify — these require an
     * installer URL and a SHA-256 per version. winget/choco resolve
     * from their own repositories at install time.
     */
    public function requiresBinary(): bool
    {
        return match ($this) {
            self::Winget, self::Choco => false,
            default => true,
        };
    }

    public function requiresPackageManagerId(): bool
    {
        return $this === self::Winget || $this === self::Choco;
    }

    /**
     * Rollback is a version-pinned reinstall (`install --version X --force`).
     * Only a package manager can resolve an arbitrary prior version on demand;
     * binary types (MSI/EXE/ZIP/MSIX/portable/PowerShell) hold a single
     * installer per version, so there is nothing to roll back to.
     */
    public function supportsRollback(): bool
    {
        return $this === self::Winget || $this === self::Choco;
    }

    /**
     * Managed removal. winget and Chocolatey uninstall from their own
     * records; MSI can uninstall by product code. The remaining binary types
     * have no reliable uninstall string, so removal is not offered.
     */
    public function supportsUninstall(): bool
    {
        return match ($this) {
            self::Winget, self::Choco, self::Msi => true,
            default => false,
        };
    }

    /** Whether a given deployment action is meaningful for this type. */
    public function supports(JobAction $action): bool
    {
        return match ($action) {
            JobAction::Rollback  => $this->supportsRollback(),
            JobAction::Uninstall => $this->supportsUninstall(),
            // Install, Update and Repair are universal.
            default => true,
        };
    }
}
