<?php

namespace App\Enums;

/**
 * The browser-policy catalogue. Each type knows how to express itself on
 * every supported browser — adding a policy is one new case plus its
 * operation map; nothing else in the pipeline changes.
 *
 * Operation kinds the agent understands:
 *  - registry:      HKLM value (path, name, DWORD value)
 *  - firefox_json:  key in distribution\policies.json
 *  - unsupported:   report "unsupported" for this browser
 */
enum BrowserPolicyType: string
{
    case DisableIncognito = 'disable_incognito';
    case DisableGuestMode = 'disable_guest_mode';
    case DisablePasswordSaving = 'disable_password_saving';
    case DisableDeveloperTools = 'disable_developer_tools';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::DisableIncognito => 'Incognito / private browsing',
            self::DisableGuestMode => 'Guest mode',
            self::DisablePasswordSaving => 'Password saving',
            self::DisableDeveloperTools => 'Developer tools',
        };
    }

    /**
     * The concrete operation for one browser under enable/disable.
     * "disable" applies the restriction; "enable" explicitly allows.
     *
     * @return array{kind: string, path?: string, name?: string, value?: int|bool, key?: string}
     */
    public function operationFor(Browser $browser, string $action): array
    {
        $disable = $action === 'disable';

        return match ($this) {
            self::DisableIncognito => match ($browser) {
                Browser::Chrome => self::registry('SOFTWARE\\Policies\\Google\\Chrome', 'IncognitoModeAvailability', $disable ? 1 : 0),
                Browser::Edge => self::registry('SOFTWARE\\Policies\\Microsoft\\Edge', 'InPrivateModeAvailability', $disable ? 1 : 0),
                Browser::Brave => self::registry('SOFTWARE\\Policies\\BraveSoftware\\Brave', 'IncognitoModeAvailability', $disable ? 1 : 0),
                Browser::Firefox => self::firefox('DisablePrivateBrowsing', $disable),
                Browser::Opera => self::unsupported(),
            },

            self::DisableGuestMode => match ($browser) {
                Browser::Chrome => self::registry('SOFTWARE\\Policies\\Google\\Chrome', 'BrowserGuestModeEnabled', $disable ? 0 : 1),
                Browser::Edge => self::registry('SOFTWARE\\Policies\\Microsoft\\Edge', 'BrowserGuestModeEnabled', $disable ? 0 : 1),
                Browser::Brave => self::registry('SOFTWARE\\Policies\\BraveSoftware\\Brave', 'BrowserGuestModeEnabled', $disable ? 0 : 1),
                Browser::Firefox, Browser::Opera => self::unsupported(),
            },

            self::DisablePasswordSaving => match ($browser) {
                Browser::Chrome => self::registry('SOFTWARE\\Policies\\Google\\Chrome', 'PasswordManagerEnabled', $disable ? 0 : 1),
                Browser::Edge => self::registry('SOFTWARE\\Policies\\Microsoft\\Edge', 'PasswordManagerEnabled', $disable ? 0 : 1),
                Browser::Brave => self::registry('SOFTWARE\\Policies\\BraveSoftware\\Brave', 'PasswordManagerEnabled', $disable ? 0 : 1),
                Browser::Firefox => self::firefox('PasswordManagerEnabled', ! $disable),
                Browser::Opera => self::unsupported(),
            },

            self::DisableDeveloperTools => match ($browser) {
                Browser::Chrome => self::registry('SOFTWARE\\Policies\\Google\\Chrome', 'DeveloperToolsAvailability', $disable ? 2 : 1),
                Browser::Edge => self::registry('SOFTWARE\\Policies\\Microsoft\\Edge', 'DeveloperToolsAvailability', $disable ? 2 : 1),
                Browser::Brave => self::registry('SOFTWARE\\Policies\\BraveSoftware\\Brave', 'DeveloperToolsAvailability', $disable ? 2 : 1),
                Browser::Firefox => self::firefox('DisableDeveloperTools', $disable),
                Browser::Opera => self::unsupported(),
            },
        };
    }

    private static function registry(string $path, string $name, int $value): array
    {
        return ['kind' => 'registry', 'path' => $path, 'name' => $name, 'value' => $value];
    }

    private static function firefox(string $key, bool $value): array
    {
        return ['kind' => 'firefox_json', 'key' => $key, 'value' => $value];
    }

    private static function unsupported(): array
    {
        return ['kind' => 'unsupported'];
    }
}
