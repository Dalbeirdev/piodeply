<?php

namespace App\Enums;

/**
 * The browser-policy catalogue. Each type knows its category, a description,
 * and how to express itself on every supported browser — adding a policy is
 * one new case plus its operation map; nothing else in the pipeline changes.
 *
 * Operation kinds the agent understands:
 *  - registry:      HKLM value (path, name, DWORD value)
 *  - firefox_json:  key in distribution\policies.json
 *  - unsupported:   report "unsupported" for this browser
 *
 * NOTE: this batch covers boolean/DWORD "toggle" policies, which the agent
 * already applies. Value-typed policies (URL text, force-lists, Allow/Ask/
 * Block permission dropdowns, minimum-version) arrive in a later batch
 * alongside the matching agent operation kinds.
 */
enum BrowserPolicyType: string
{
    // Chromium policy roots — Edge/Brave mirror Chrome's schema, but not
    // always the value name (incognito is the classic exception).
    private const CHROME = 'SOFTWARE\\Policies\\Google\\Chrome';
    private const EDGE = 'SOFTWARE\\Policies\\Microsoft\\Edge';
    private const BRAVE = 'SOFTWARE\\Policies\\BraveSoftware\\Brave';

    // ── Password Management ──────────────────────────────────────────────
    case DisablePasswordSaving = 'disable_password_saving';
    case DisablePasswordLeakDetection = 'disable_password_leak_detection';

    // ── Private Browsing ─────────────────────────────────────────────────
    case DisableIncognito = 'disable_incognito';
    case DisableGuestMode = 'disable_guest_mode';

    // ── Autofill ─────────────────────────────────────────────────────────
    case DisableAddressAutofill = 'disable_address_autofill';
    case DisableCreditCardAutofill = 'disable_credit_card_autofill';

    // ── Browser Sync & Sign-in ───────────────────────────────────────────
    case DisableBrowserSync = 'disable_browser_sync';
    case DisableBrowserSignin = 'disable_browser_signin';

    // ── Developer Tools ──────────────────────────────────────────────────
    case DisableDeveloperTools = 'disable_developer_tools';

    // ── Browser Security ─────────────────────────────────────────────────
    case DisableQuic = 'disable_quic';

    // ── Notifications ────────────────────────────────────────────────────
    case DisableNotifications = 'disable_notifications';

    // ── Location ─────────────────────────────────────────────────────────
    case DisableLocation = 'disable_location';

    // ── Camera & Microphone ──────────────────────────────────────────────
    case DisableCamera = 'disable_camera';
    case DisableMicrophone = 'disable_microphone';

    // ── Clipboard ────────────────────────────────────────────────────────
    case DisableClipboard = 'disable_clipboard';

    // ── Printing ─────────────────────────────────────────────────────────
    case DisablePrinting = 'disable_printing';

    // ── Advanced Enterprise ──────────────────────────────────────────────
    case DisableTranslate = 'disable_translate';
    case DisableWebUsb = 'disable_web_usb';
    case DisableWebBluetooth = 'disable_web_bluetooth';
    case DisableWebSerial = 'disable_web_serial';

    /** Category display order for grouped UIs. */
    public const CATEGORY_ORDER = [
        'Password Management', 'Private Browsing', 'Autofill', 'Browser Sync & Sign-in',
        'Developer Tools', 'Browser Security', 'Notifications', 'Location',
        'Camera & Microphone', 'Clipboard', 'Printing', 'Advanced Enterprise',
    ];

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, list<self>> cases grouped by category, ordered. */
    public static function byCategory(): array
    {
        $grouped = [];
        foreach (self::CATEGORY_ORDER as $category) {
            $grouped[$category] = [];
        }
        foreach (self::cases() as $case) {
            $grouped[$case->category()][] = $case;
        }

        return array_filter($grouped);
    }

    public function category(): string
    {
        return match ($this) {
            self::DisablePasswordSaving, self::DisablePasswordLeakDetection => 'Password Management',
            self::DisableIncognito, self::DisableGuestMode => 'Private Browsing',
            self::DisableAddressAutofill, self::DisableCreditCardAutofill => 'Autofill',
            self::DisableBrowserSync, self::DisableBrowserSignin => 'Browser Sync & Sign-in',
            self::DisableDeveloperTools => 'Developer Tools',
            self::DisableQuic => 'Browser Security',
            self::DisableNotifications => 'Notifications',
            self::DisableLocation => 'Location',
            self::DisableCamera, self::DisableMicrophone => 'Camera & Microphone',
            self::DisableClipboard => 'Clipboard',
            self::DisablePrinting => 'Printing',
            self::DisableTranslate, self::DisableWebUsb, self::DisableWebBluetooth, self::DisableWebSerial => 'Advanced Enterprise',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DisablePasswordSaving => 'Password saving',
            self::DisablePasswordLeakDetection => 'Password leak detection',
            self::DisableIncognito => 'Incognito / private browsing',
            self::DisableGuestMode => 'Guest mode',
            self::DisableAddressAutofill => 'Address autofill',
            self::DisableCreditCardAutofill => 'Credit-card autofill',
            self::DisableBrowserSync => 'Browser sync',
            self::DisableBrowserSignin => 'Browser sign-in',
            self::DisableDeveloperTools => 'Developer tools',
            self::DisableQuic => 'QUIC protocol',
            self::DisableNotifications => 'Web notifications',
            self::DisableLocation => 'Location access',
            self::DisableCamera => 'Camera access',
            self::DisableMicrophone => 'Microphone access',
            self::DisableClipboard => 'Clipboard access',
            self::DisablePrinting => 'Printing',
            self::DisableTranslate => 'Page translation',
            self::DisableWebUsb => 'WebUSB',
            self::DisableWebBluetooth => 'WebBluetooth',
            self::DisableWebSerial => 'WebSerial',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DisablePasswordSaving => 'Stops the browser from offering to save passwords or using its built-in password manager.',
            self::DisablePasswordLeakDetection => 'Turns off checking saved passwords against known data breaches.',
            self::DisableIncognito => 'Blocks incognito / InPrivate / private-browsing windows.',
            self::DisableGuestMode => 'Blocks guest browsing profiles.',
            self::DisableAddressAutofill => 'Stops the browser from saving and filling street addresses.',
            self::DisableCreditCardAutofill => 'Stops the browser from saving and filling payment cards.',
            self::DisableBrowserSync => 'Disables syncing bookmarks, history and settings to a browser account.',
            self::DisableBrowserSignin => 'Prevents users from signing into the browser with an account.',
            self::DisableDeveloperTools => 'Blocks DevTools, view-source and the JavaScript console.',
            self::DisableQuic => 'Disables the QUIC transport protocol (forces classic TLS/TCP).',
            self::DisableNotifications => 'Blocks websites from showing desktop notifications.',
            self::DisableLocation => 'Blocks websites from requesting device location.',
            self::DisableCamera => 'Blocks websites from accessing the camera.',
            self::DisableMicrophone => 'Blocks websites from accessing the microphone.',
            self::DisableClipboard => 'Blocks websites from reading the system clipboard.',
            self::DisablePrinting => 'Disables printing from the browser.',
            self::DisableTranslate => 'Turns off the built-in page-translation feature.',
            self::DisableWebUsb => 'Blocks websites from connecting to USB devices (WebUSB).',
            self::DisableWebBluetooth => 'Blocks websites from connecting to Bluetooth devices (WebBluetooth).',
            self::DisableWebSerial => 'Blocks websites from connecting to serial devices (WebSerial).',
        };
    }

    /** Browser policies are read at launch, so a relaunch applies them. */
    public function requiresRestart(): bool
    {
        return true;
    }

    /** The agent enforces via the Windows registry / Firefox policies file. */
    public function platform(): string
    {
        return 'Windows';
    }

    /**
     * Browsers this policy can actually act on — derived from the operation
     * map, so it can never disagree with what the agent will do.
     *
     * @return list<Browser>
     */
    public function supportedBrowsers(): array
    {
        return array_values(array_filter(
            Browser::cases(),
            fn (Browser $b) => ($this->operationFor($b, 'disable')['kind'] ?? 'unsupported') !== 'unsupported',
        ));
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
                Browser::Chrome => self::registry(self::CHROME, 'IncognitoModeAvailability', $disable ? 1 : 0),
                Browser::Edge => self::registry(self::EDGE, 'InPrivateModeAvailability', $disable ? 1 : 0),
                Browser::Brave => self::registry(self::BRAVE, 'IncognitoModeAvailability', $disable ? 1 : 0),
                Browser::Firefox => self::firefox('DisablePrivateBrowsing', $disable),
                Browser::Opera => self::unsupported(),
            },

            self::DisableGuestMode => self::chromiumOnly($browser, 'BrowserGuestModeEnabled', $disable ? 0 : 1),

            self::DisablePasswordSaving => match ($browser) {
                Browser::Chrome, Browser::Edge, Browser::Brave => self::chromium($browser, 'PasswordManagerEnabled', $disable ? 0 : 1),
                Browser::Firefox => self::firefox('PasswordManagerEnabled', ! $disable),
                Browser::Opera => self::unsupported(),
            },

            self::DisablePasswordLeakDetection => self::chromiumOnly($browser, 'PasswordLeakDetectionEnabled', $disable ? 0 : 1),

            self::DisableAddressAutofill => self::chromiumOnly($browser, 'AutofillAddressEnabled', $disable ? 0 : 1),
            self::DisableCreditCardAutofill => self::chromiumOnly($browser, 'AutofillCreditCardEnabled', $disable ? 0 : 1),

            self::DisableBrowserSync => match ($browser) {
                Browser::Chrome, Browser::Edge, Browser::Brave => self::chromium($browser, 'SyncDisabled', $disable ? 1 : 0),
                Browser::Firefox => self::firefox('DisableFirefoxAccounts', $disable),
                Browser::Opera => self::unsupported(),
            },

            self::DisableBrowserSignin => match ($browser) {
                Browser::Chrome, Browser::Edge, Browser::Brave => self::chromium($browser, 'BrowserSignin', $disable ? 0 : 1),
                Browser::Firefox => self::firefox('DisableFirefoxAccounts', $disable),
                Browser::Opera => self::unsupported(),
            },

            self::DisableDeveloperTools => match ($browser) {
                Browser::Chrome, Browser::Edge, Browser::Brave => self::chromium($browser, 'DeveloperToolsAvailability', $disable ? 2 : 1),
                Browser::Firefox => self::firefox('DisableDeveloperTools', $disable),
                Browser::Opera => self::unsupported(),
            },

            self::DisableQuic => self::chromiumOnly($browser, 'QuicAllowed', $disable ? 0 : 1),

            self::DisableNotifications => self::chromiumOnly($browser, 'DefaultNotificationsSetting', $disable ? 2 : 1),
            self::DisableLocation => self::chromiumOnly($browser, 'DefaultGeolocationSetting', $disable ? 2 : 1),
            self::DisableCamera => self::chromiumOnly($browser, 'VideoCaptureAllowed', $disable ? 0 : 1),
            self::DisableMicrophone => self::chromiumOnly($browser, 'AudioCaptureAllowed', $disable ? 0 : 1),
            self::DisableClipboard => self::chromiumOnly($browser, 'DefaultClipboardSetting', $disable ? 2 : 1),

            self::DisablePrinting => self::chromiumOnly($browser, 'PrintingEnabled', $disable ? 0 : 1),
            self::DisableTranslate => self::chromiumOnly($browser, 'TranslateEnabled', $disable ? 0 : 1),

            self::DisableWebUsb => self::chromiumOnly($browser, 'DefaultWebUsbGuardSetting', $disable ? 2 : 3),
            self::DisableWebBluetooth => self::chromiumOnly($browser, 'DefaultWebBluetoothGuardSetting', $disable ? 2 : 3),
            self::DisableWebSerial => self::chromiumOnly($browser, 'DefaultSerialGuardSetting', $disable ? 2 : 3),
        };
    }

    /** A registry op on the given Chromium browser's policy root. */
    private static function chromium(Browser $browser, string $name, int $value): array
    {
        $root = match ($browser) {
            Browser::Chrome => self::CHROME,
            Browser::Edge => self::EDGE,
            Browser::Brave => self::BRAVE,
            default => null,
        };

        return $root === null ? self::unsupported() : self::registry($root, $name, $value);
    }

    /** Same registry value name across Chrome/Edge/Brave; unsupported elsewhere. */
    private static function chromiumOnly(Browser $browser, string $name, int $value): array
    {
        return match ($browser) {
            Browser::Chrome, Browser::Edge, Browser::Brave => self::chromium($browser, $name, $value),
            Browser::Firefox, Browser::Opera => self::unsupported(),
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
