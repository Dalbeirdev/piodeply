<?php

namespace App\Enums;

/**
 * What a policy wants done. Broader than JobAction: some policy actions
 * map onto different job actions depending on state (e.g. Block queues
 * uninstall jobs; ForceUpdate queues rollback jobs, which the agent runs
 * as `winget install --version X --force`).
 */
enum PolicyAction: string
{
    case Install = 'install';
    case Update = 'update';
    case ForceUpdate = 'force_update';
    case Uninstall = 'uninstall';
    case Block = 'block';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Install => 'Install',
            self::Update => 'Auto update',
            self::ForceUpdate => 'Force update',
            self::Uninstall => 'Remove',
            self::Block => 'Block',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Install => "Install — put it on machines that don't have it",
            self::Update => 'Auto update — keep it current on machines that have it',
            self::ForceUpdate => 'Force update — reinstall the desired version even if already current',
            self::Uninstall => "Remove — uninstall it wherever it's found",
            self::Block => 'Block — forbidden software, remove whenever it is detected',
        };
    }
}
