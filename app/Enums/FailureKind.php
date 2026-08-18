<?php

namespace App\Enums;

/**
 * What kind of failure a deployment hit, and therefore what to do about it.
 *
 * The engine used to ask only "have the retries run out?", so a machine with
 * a full disk looked exactly like a broken package: three identical attempts,
 * then a red row. These three answers separate the cases that a retry can fix
 * from the ones that need a person — and, crucially, say WHICH person.
 */
enum FailureKind: string
{
    /** Something momentary — another install running, a corrupt download. */
    case Transient = 'transient';

    /**
     * This software cannot be installed this way on this machine: a per-user
     * or MSIX package under a machine-wide install, no matching architecture,
     * an app another mechanism owns. Retrying is identical; the catalogue
     * entry is what has to change.
     */
    case Package = 'package';

    /**
     * Nothing is wrong with the package — the machine cannot accept any
     * install right now: no disk space, access denied, a reboot pending.
     * No other installer or engine would fare better, so trying more of them
     * only delays the person who can actually fix it.
     */
    case Machine = 'machine';

    /**
     * An exit code we have never classified — including plain 1, which is
     * what most installers return for "it did not work". Both the Edge
     * mismatch and the Teams MSIX failure arrived here first.
     */
    case Unknown = 'unknown';

    /**
     * Retry a failure that repeating could plausibly clear.
     *
     * Unknown retries too, deliberately: the commonest unclassified code is
     * a bare 1, and plenty of those are a momentary file lock or an antivirus
     * scanner. Refusing to retry everything we have not catalogued would stop
     * retrying the majority of real failures — a far bigger regression than
     * the wasted attempts it saves. Unknown earns attention once the retries
     * are spent, not instead of them.
     */
    public function shouldRetry(): bool
    {
        return $this === self::Transient || $this === self::Unknown;
    }

    /** Who needs to know: the machine's owner, or whoever curates software. */
    public function ownedByOperator(): bool
    {
        return $this === self::Package || $this === self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::Transient => 'Temporary — will retry',
            self::Package   => 'This package cannot install here',
            self::Machine   => 'The machine cannot accept installs',
            self::Unknown   => 'Unrecognised failure',
        };
    }
}
