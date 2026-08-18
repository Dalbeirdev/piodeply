<?php

namespace App\Enums;

/**
 * How a catalogue entry is actually managed — the thing "active/inactive"
 * used to stand in for and got wrong. Deactivating Edge hid it entirely, so
 * a client had no way to see it was current; it just vanished. A package can
 * be perfectly real and still not be something this platform installs.
 */
enum PackageMode: string
{
    /** The default: winget installs and updates it, as today. */
    case Deploy = 'deploy';

    /**
     * Windows ships and updates this itself (Edge). Never queued; shown with
     * its inventoried version so a client can see it is current without this
     * platform ever touching it.
     */
    case OsManaged = 'os_managed';

    /**
     * A Store/MSIX package. Per-user by design, so an agent running as
     * SYSTEM cannot install or update it machine-wide (Teams is the case
     * that found this) — not a bug in the package, a mismatch of mechanism.
     */
    case Store = 'store';

    /** Known not to work here at all (Spotify, Discord — per-user only). */
    case Unsupported = 'unsupported';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Deploy      => 'Deploy',
            self::OsManaged   => 'OS-managed',
            self::Store       => 'Store / MSIX',
            self::Unsupported => 'Unsupported',
        };
    }

    /** Whether a job may ever be queued for this package. */
    public function isDeployable(): bool
    {
        return $this === self::Deploy;
    }

    /**
     * What a CLIENT is told when the package cannot be ordered — never
     * "removed" or silent, since the software is real and worth explaining.
     */
    public function clientExplanation(): string
    {
        return match ($this) {
            self::Deploy      => '',
            self::OsManaged   => 'Kept current by Windows itself — this platform does not need to touch it.',
            self::Store       => 'Installed per user through the Microsoft Store, not by this platform.',
            self::Unsupported => 'Cannot be installed machine-wide by any current method.',
        };
    }
}
