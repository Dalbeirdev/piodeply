<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-900 leading-tight">Branding</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif

            <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                What your users and machine operators see. Leave a name empty to use the platform default.
            </div>

            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <div>
                    <x-label for="portal_name" value="Portal name" />
                    <x-input id="portal_name" type="text" class="mt-1 block w-full" wire:model="portal_name"
                             placeholder="e.g. Sid Services IT Portal" />
                    <p class="mt-1 text-xs text-slate-500">Shown in the sidebar to everyone in your organisation.</p>
                    <x-input-error for="portal_name" class="mt-1" />
                </div>

                <div class="border-t pt-5">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Tray icon on your machines</h3>

                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="show_tray_icon" class="mt-0.5 rounded border-slate-300">
                        <span>
                            <b>Show the agent tray icon</b> — the small status icon near the clock on
                            each machine. Untick to hide it completely; the agent keeps working
                            silently either way.
                        </span>
                    </label>

                    <div class="mt-4">
                        <x-label for="tray_name" value="Tray icon name" />
                        <x-input id="tray_name" type="text" class="mt-1 block w-full" wire:model="tray_name"
                                 placeholder="e.g. Sid Services IT Support" />
                        <p class="mt-1 text-xs text-slate-500">
                            The name shown when someone hovers or right-clicks the icon —
                            your IT brand instead of "PioDeploy Agent".
                        </p>
                        <x-input-error for="tray_name" class="mt-1" />
                    </div>
                </div>

                <div class="flex justify-end">
                    <x-button>Save branding</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
