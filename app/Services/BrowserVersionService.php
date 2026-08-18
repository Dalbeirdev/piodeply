<?php

namespace App\Services;

use App\Enums\Browser;
use App\Models\BrowserVersionObservation;
use App\Models\Computer;
use Illuminate\Support\Collection;

/**
 * "Is this machine's browser actually current?" — a question this platform
 * has never been able to answer for Edge, because Edge cannot be deployed
 * (see PackageMode::OsManaged) and winget's own listing can lag what is
 * really installed: the same machine reported Firefox as two different
 * versions from winget vs. the registry in production, winget behind.
 *
 * Comparison is always FLEET-relative, never against Microsoft's release
 * feed: nine machines on the same version and one behind is provable from
 * this platform's own data; "is 150.0.4078.83 the newest Edge build" is not,
 * without a network call this service deliberately never makes.
 */
class BrowserVersionService
{
    /**
     * winget's local package cache can report a version behind what a
     * self-updating browser actually runs (observed directly: Firefox showed
     * two different version strings from winget vs. registry on one
     * machine, registry correct). Only sources that read the installed
     * program's own record are trusted here — this does not touch
     * available_version/hasUpdate(), which is winget's job and stays as is.
     */
    private const TRUSTED_SOURCES = ['registry', 'msi'];

    /**
     * How long a version may sit behind the fleet's newest before it reads
     * as "stuck" rather than "hasn't caught up yet". A browser update
     * reaching a fleet over a few days is normal; motionless for three weeks
     * while peers move on is what a disabled updater looks like.
     */
    public const STUCK_AFTER_DAYS = 21;

    /**
     * Matched by name prefix, evidence-based from what agents actually send
     * (surveyed against the live fleet, not guessed): registry gives
     * "Microsoft Edge", "Mozilla Firefox (x64 en-US)", "Brave"; the MSI
     * source gives "Google Chrome". Locale/arch suffixes vary, hence prefix
     * matching rather than exact.
     */
    private function identify(string $name): ?Browser
    {
        $n = strtolower(trim($name));

        return match (true) {
            str_starts_with($n, 'microsoft edge')  => Browser::Edge,
            str_starts_with($n, 'google chrome')   => Browser::Chrome,
            str_starts_with($n, 'mozilla firefox') => Browser::Firefox,
            str_starts_with($n, 'brave')           => Browser::Brave,
            str_starts_with($n, 'opera')            => Browser::Opera,
            default => null,
        };
    }

    /**
     * Called once per agent report, right after the full software-inventory
     * replace. Appends rather than replaces: a row is untouched (its
     * first_seen_at stays put) unless this exact version was not already on
     * record for this machine.
     *
     * @param  list<array{name?: string, version?: string, source?: string}>  $items
     */
    public function recordFromInventory(Computer $computer, array $items): void
    {
        $now = now();
        $matched = [];

        foreach ($items as $item) {
            $source = (string) ($item['source'] ?? '');
            if (! in_array($source, self::TRUSTED_SOURCES, true)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $version = trim((string) ($item['version'] ?? ''));
            if ($name === '' || $version === '') {
                continue;
            }

            $browser = $this->identify($name);
            // One inventory report should carry each browser once from a
            // trusted source; if it somehow carries two, keep the first
            // rather than let a second write reset first_seen_at.
            if ($browser === null || isset($matched[$browser->value])) {
                continue;
            }
            $matched[$browser->value] = true;

            $observation = BrowserVersionObservation::firstOrNew([
                'computer_id' => $computer->id,
                'browser'     => $browser->value,
                'version'     => $version,
            ]);
            if (! $observation->exists) {
                $observation->first_seen_at = $now;
            }
            $observation->last_seen_at = $now;
            $observation->save();
        }
    }

    /**
     * The observation still current for each (computer, browser) — the one
     * whose last_seen_at is most recent, since every report bumps whichever
     * version is presently installed and leaves superseded ones behind.
     *
     * @param  list<int>|null  $computerIds
     * @return Collection<int, BrowserVersionObservation>
     */
    private function currentPerComputer(?array $computerIds = null): Collection
    {
        return BrowserVersionObservation::query()
            ->with('computer:id,project_id', 'computer.project:id,client_id')
            ->when($computerIds !== null, fn ($q) => $q->whereIn('computer_id', $computerIds))
            ->get()
            ->groupBy(fn (BrowserVersionObservation $o) => $o->computer_id.':'.$o->browser->value)
            ->map(fn (Collection $g) => $g->sortByDesc('last_seen_at')->first())
            ->values();
    }

    /**
     * Newest version seen per browser, grouped by CLIENT — a health score
     * must only ever compare a machine against its own client's fleet,
     * never against a different MSP customer's, and this is the one query
     * that answers it for every client at once (the batch-safe path for the
     * dashboard, the fleet health report, and the PDF export — all of which
     * loop over many computers and would N+1 a per-computer version).
     *
     * @param  list<int>|null  $computerIds  confine to these machines
     * @return array<int, array<string, string>>  client_id => [browser value => version]
     */
    public function fleetLatestByClient(?array $computerIds = null): array
    {
        return $this->currentPerComputer($computerIds)
            ->groupBy(fn (BrowserVersionObservation $o) => $o->computer->project->client_id)
            ->map(fn (Collection $forClient) => $forClient
                ->groupBy(fn (BrowserVersionObservation $o) => $o->browser->value)
                ->map(fn (Collection $versions) => $versions->reduce(
                    fn (?string $best, BrowserVersionObservation $o) => $best === null || version_compare($o->version, $best, '>')
                        ? $o->version : $best,
                    null
                ))
                ->all())
            ->all();
    }

    /** Convenience for a single-computer view, where a batch preload is overkill. */
    public function fleetLatestForClient(int $clientId): array
    {
        $computerIds = Computer::whereHas('project', fn ($q) => $q->where('client_id', $clientId))->pluck('id')->all();

        return $this->fleetLatestByClient($computerIds)[$clientId] ?? [];
    }

    /**
     * Every tracked browser on this machine, with the fleet comparison and
     * whether it reads as stuck — the view a computer's own page shows.
     *
     * @return list<array{browser: Browser, version: string, fleet_latest: ?string, behind: bool, since: \Illuminate\Support\Carbon, stuck: bool}>
     */
    public function summaryFor(Computer $computer, array $fleetLatestForClient): array
    {
        return BrowserVersionObservation::where('computer_id', $computer->id)->get()
            ->groupBy(fn (BrowserVersionObservation $o) => $o->browser->value)
            ->map(fn (Collection $g) => $g->sortByDesc('last_seen_at')->first())
            ->map(function (BrowserVersionObservation $o) use ($fleetLatestForClient) {
                $latest = $fleetLatestForClient[$o->browser->value] ?? null;
                $behind = $latest !== null && version_compare($o->version, $latest, '<');

                return [
                    'browser'      => $o->browser,
                    'version'      => $o->version,
                    'fleet_latest' => $latest,
                    'behind'       => $behind,
                    'since'        => $o->first_seen_at,
                    // Not moving is only a concern while behind: a machine
                    // that IS the newest can sit on a version for months —
                    // that is what "current" looks like, not a fault.
                    'stuck'        => $behind && $o->first_seen_at->diffInDays(now()) >= self::STUCK_AFTER_DAYS,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Plain-English notes for whichever of this machine's browsers are
     * stuck — what Computer::healthScore() folds in. Empty when nothing is,
     * which is the common case and must stay cheap: no query beyond the
     * one summaryFor() already runs.
     *
     * @return list<string>
     */
    public function stuckNotes(Computer $computer, array $fleetLatestForClient): array
    {
        if ($fleetLatestForClient === []) {
            return [];
        }

        return collect($this->summaryFor($computer, $fleetLatestForClient))
            ->filter(fn (array $row) => $row['stuck'])
            ->map(fn (array $row) => "{$row['browser']->label()} stuck on {$row['version']} for "
                .(int) $row['since']->diffInDays(now())." days (fleet is on {$row['fleet_latest']})")
            ->values()
            ->all();
    }
}
