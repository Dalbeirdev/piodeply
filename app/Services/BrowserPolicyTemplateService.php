<?php

namespace App\Services;

use App\Enums\BrowserPolicyType as T;
use App\Models\BrowserPolicy;
use App\Models\BrowserPolicyTemplate;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Policy templates: seven built-in bundles (code-defined, so they track the
 * catalogue with no seeding) plus admin-saved custom templates in the DB.
 * Applying a template fans individual BrowserPolicy rows out to a project,
 * skipping types the project already has — never overwriting local choices.
 */
class BrowserPolicyTemplateService
{
    /**
     * The built-in bundles. Every entry is a catalogue case, so a template
     * can never reference a policy that doesn't exist.
     *
     * @return array<string, array{name: string, description: string, types: list<T>}>
     */
    public static function builtins(): array
    {
        return [
            'high-security' => [
                'name' => 'High Security',
                'description' => 'Maximum restriction for sensitive environments: no private browsing, no stored credentials, no device APIs, no AI.',
                'types' => [
                    T::DisableIncognito, T::DisableGuestMode, T::DisablePasswordSaving,
                    T::DisableAddressAutofill, T::DisableCreditCardAutofill,
                    T::DisableBrowserSync, T::DisableBrowserSignin, T::DisableDeveloperTools,
                    T::BlockThirdPartyCookies, T::DisablePopups, T::DisableScreenCapture,
                    T::DisableClipboard, T::DisableLocation, T::DisableAiAssistants,
                    T::DisableWebUsb, T::DisableWebBluetooth, T::DisableWebSerial,
                ],
            ],
            'standard-office' => [
                'name' => 'Standard Office',
                'description' => 'Sensible defaults for everyday office fleets: private browsing and stored cards off, distractions removed.',
                'types' => [
                    T::DisableIncognito, T::DisableGuestMode, T::DisablePasswordSaving,
                    T::DisableCreditCardAutofill, T::BlockThirdPartyCookies, T::DisablePopups,
                    T::DisableShoppingAssistant, T::DisableNewTabFeed,
                    T::DisableMicrosoftRewards, T::DisableBrowserGames,
                ],
            ],
            'developer' => [
                'name' => 'Developer',
                'description' => 'Light touch for engineering machines: DevTools stay available, only account hygiene is enforced.',
                'types' => [
                    T::DisableGuestMode, T::DisablePasswordSaving, T::DisableBrowserSync,
                ],
            ],
            'kiosk' => [
                'name' => 'Kiosk',
                'description' => 'Locked-down shared/public terminals: nothing persists, nothing configurable, no hardware access.',
                'types' => [
                    T::DisableIncognito, T::DisableGuestMode, T::DisablePasswordSaving,
                    T::DisableAddressAutofill, T::DisableCreditCardAutofill,
                    T::DisableBrowserSync, T::DisableBrowserSignin, T::DisableDeveloperTools,
                    T::DisableDownloads, T::ClearCookiesOnExit, T::DisableBrowsingHistory,
                    T::DisableBookmarkEditing, T::DisableNotifications, T::DisablePopups,
                    T::DisableClipboard, T::DisableCamera, T::DisableMicrophone,
                    T::DisableLocation, T::DisableScreenCapture, T::DisablePrinting,
                    T::DisableAiAssistants, T::DisableNewTabFeed,
                    T::DisableMicrosoftRewards, T::DisableBrowserGames,
                ],
            ],
            'finance' => [
                'name' => 'Finance',
                'description' => 'For teams handling financial data: High Security plus blocked downloads, session-only cookies and no capture devices.',
                'types' => [
                    T::DisableIncognito, T::DisableGuestMode, T::DisablePasswordSaving,
                    T::DisableAddressAutofill, T::DisableCreditCardAutofill,
                    T::DisableBrowserSync, T::DisableBrowserSignin, T::DisableDeveloperTools,
                    T::BlockThirdPartyCookies, T::ClearCookiesOnExit, T::DisablePopups,
                    T::DisableScreenCapture, T::DisableClipboard, T::DisableDownloads,
                    T::DisableCamera, T::DisableMicrophone, T::DisableLocation,
                    T::DisableAiAssistants, T::DisableWebUsb, T::DisableWebBluetooth, T::DisableWebSerial,
                ],
            ],
            'healthcare' => [
                'name' => 'Healthcare',
                'description' => 'Patient-data protection: nothing cached between sessions, no screen capture, and no page content sent to AI or translation services.',
                'types' => [
                    T::DisableIncognito, T::DisableGuestMode, T::DisablePasswordSaving,
                    T::DisableAddressAutofill, T::DisableCreditCardAutofill,
                    T::DisableBrowserSync, T::DisableBrowserSignin,
                    T::BlockThirdPartyCookies, T::ClearCookiesOnExit,
                    T::DisableNotifications, T::DisablePopups, T::DisableScreenCapture,
                    T::DisableAiAssistants, T::DisableTranslate, T::DisableLocation,
                ],
            ],
            'education' => [
                'name' => 'Education',
                'description' => 'Classroom fleets: private browsing and distractions off, camera and microphone left available for lessons.',
                'types' => [
                    T::DisableIncognito, T::DisableGuestMode, T::DisableDeveloperTools,
                    T::DisablePopups, T::DisableBrowserGames, T::DisableShoppingAssistant,
                    T::DisableNewTabFeed, T::DisableMicrosoftRewards, T::DisableAiAssistants,
                ],
            ],
        ];
    }

    /**
     * Every template the UI offers: built-ins first, then custom ones. Each
     * carries 'entries' (full per-policy definitions: type/action/browsers/
     * settings) and 'types' (just the enum cases, for chips and counts).
     *
     * @return Collection<int, array{key: string, name: string, description: ?string, types: list<T>, entries: list<array>, custom: bool, model: ?BrowserPolicyTemplate}>
     */
    public function all(): Collection
    {
        $builtin = collect(self::builtins())->map(function (array $t, string $key) {
            $entries = array_map(fn (T $type) => [
                'type' => $type, 'action' => 'disable', 'browsers' => ['all'], 'settings' => null,
            ], $t['types']);

            return [
                'key' => $key, 'name' => $t['name'], 'description' => $t['description'],
                'types' => $t['types'], 'entries' => $entries, 'custom' => false, 'model' => null,
            ];
        })->values();

        $custom = BrowserPolicyTemplate::orderBy('name')->get()->map(function ($m) {
            $entries = collect($m->policies)
                ->map(function (array $p) {
                    $type = T::tryFrom($p['type'] ?? '');

                    return $type === null ? null : [
                        'type'     => $type,
                        'action'   => in_array($p['action'] ?? 'disable', ['disable', 'enable'], true) ? ($p['action'] ?? 'disable') : 'disable',
                        'browsers' => $p['browsers'] ?? ['all'],
                        'settings' => $p['settings'] ?? null,
                    ];
                })
                ->filter()
                ->values();

            return [
                'key' => 'custom-'.$m->id, 'name' => $m->name, 'description' => $m->description,
                'types' => $entries->pluck('type')->all(), 'entries' => $entries->all(),
                'custom' => true, 'model' => $m,
            ];
        });

        return $builtin->concat($custom);
    }

    /** Resolve a template key from all() back to its definition, or null. */
    public function find(string $key): ?array
    {
        return $this->all()->firstWhere('key', $key);
    }

    /**
     * Create one BrowserPolicy per template entry on the project. Types the
     * project already has are skipped, not overwritten.
     *
     * @param  array{name: string, entries: list<array{type: T, action: string, browsers: array, settings: ?array}>}  $template
     * @return array{created: int, skipped: int}
     */
    public function apply(array $template, Project $project, ?int $userId = null): array
    {
        $existing = BrowserPolicy::where('project_id', $project->id)->pluck('type')
            ->map(fn ($t) => $t->value)->all();

        $created = $skipped = 0;

        foreach ($template['entries'] as $entry) {
            $type = $entry['type'];

            if (in_array($type->value, $existing, true)) {
                $skipped++;

                continue;
            }

            BrowserPolicy::create([
                'name'        => "{$template['name']}: {$type->label()}",
                'project_id'  => $project->id,
                'type'        => $type->value,
                'browsers'    => $entry['browsers'] ?: ['all'],
                'action'      => $entry['action'],
                'settings'    => $entry['settings'],
                'status'      => 'active',
                'description' => "Applied from the {$template['name']} template.",
                'created_by'  => $userId,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Save a project's current policies as a reusable custom template. */
    public function captureFromProject(string $name, ?string $description, Project $project, ?int $userId = null): BrowserPolicyTemplate
    {
        $policies = BrowserPolicy::where('project_id', $project->id)
            ->get()
            ->map(fn (BrowserPolicy $p) => [
                'type'     => $p->type->value,
                'action'   => $p->action,
                'browsers' => $p->browsers ?? ['all'],
                'settings' => $p->settings,
            ])
            ->values()
            ->all();

        return BrowserPolicyTemplate::create([
            'name'        => $name,
            'description' => $description,
            'policies'    => $policies,
            'created_by'  => $userId,
        ]);
    }

    /* ─────────────────────── Import / export ─────────────────────────── */

    public const EXPORT_FORMAT = 'piodeploy.browser-policies';

    public const EXPORT_VERSION = 1;

    /**
     * A portable JSON document from a template definition (built-in or
     * custom) — the same shape import consumes.
     */
    public function exportTemplate(array $template): array
    {
        return [
            'format'      => self::EXPORT_FORMAT,
            'version'     => self::EXPORT_VERSION,
            'name'        => $template['name'],
            'description' => $template['description'],
            'policies'    => array_map(fn (array $entry) => [
                'type'     => $entry['type']->value,
                'action'   => $entry['action'],
                'browsers' => $entry['browsers'],
                'settings' => $entry['settings'],
            ], $template['entries']),
        ];
    }

    /** A portable JSON document from a project's current policies. */
    public function exportProject(Project $project): array
    {
        $captured = BrowserPolicy::where('project_id', $project->id)->get()
            ->map(fn (BrowserPolicy $p) => [
                'type'     => $p->type->value,
                'action'   => $p->action,
                'browsers' => $p->browsers ?? ['all'],
                'settings' => $p->settings,
            ])->values()->all();

        return [
            'format'      => self::EXPORT_FORMAT,
            'version'     => self::EXPORT_VERSION,
            'name'        => "{$project->name} browser policies",
            'description' => 'Exported from '.config('app.name').' on '.now()->toDateString().'.',
            'policies'    => $captured,
        ];
    }

    /**
     * Import a document as a custom template. Unknown policy types (from a
     * newer or older catalogue) are skipped, not fatal — the count is
     * reported so nothing is silently lost.
     *
     * @return array{template: BrowserPolicyTemplate, imported: int, skipped: int}
     *
     * @throws \InvalidArgumentException when the document is not ours.
     */
    public function import(array $payload, string $name, ?int $userId = null): array
    {
        if (($payload['format'] ?? null) !== self::EXPORT_FORMAT || ! is_array($payload['policies'] ?? null)) {
            throw new \InvalidArgumentException('Not a PioDeploy browser-policy export.');
        }

        $imported = [];
        $skipped = 0;

        foreach ($payload['policies'] as $entry) {
            $type = T::tryFrom($entry['type'] ?? '');

            if ($type === null) {
                $skipped++;

                continue;
            }

            $imported[] = [
                'type'     => $type->value,
                'action'   => in_array($entry['action'] ?? 'disable', ['disable', 'enable'], true) ? ($entry['action'] ?? 'disable') : 'disable',
                'browsers' => is_array($entry['browsers'] ?? null) ? $entry['browsers'] : ['all'],
                'settings' => is_array($entry['settings'] ?? null) ? $entry['settings'] : null,
            ];
        }

        if ($imported === []) {
            throw new \InvalidArgumentException('The file contains no policies this catalogue recognises.');
        }

        $template = BrowserPolicyTemplate::create([
            'name'        => $name,
            'description' => is_string($payload['description'] ?? null) ? $payload['description'] : null,
            'policies'    => $imported,
            'created_by'  => $userId,
        ]);

        return ['template' => $template, 'imported' => count($imported), 'skipped' => $skipped];
    }
}
