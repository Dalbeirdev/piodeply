<?php

namespace App\Enums;

enum InstallerType: string
{
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
}
