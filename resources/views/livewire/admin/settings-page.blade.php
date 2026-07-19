<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Settings') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <form wire:submit="save" class="pd-card p-6 space-y-6">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Branding</h3>
                    <div class="max-w-sm">
                        <x-label for="company_name" value="Company name" />
                        <x-input id="company_name" type="text" class="mt-1 block w-full" wire:model="company_name" />
                        <p class="mt-1 text-xs text-slate-500">Shown in the sidebar next to the PioDeploy logo.</p>
                        <x-input-error for="company_name" class="mt-1" />
                    </div>
                </div>

                <div class="border-t pt-5">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Agents</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-label for="online_threshold_seconds" value="Online threshold (seconds)" />
                            <x-input id="online_threshold_seconds" type="number" min="60" max="3600"
                                     class="mt-1 block w-32" wire:model="online_threshold_seconds" />
                            <p class="mt-1 text-xs text-slate-500">A machine is “online” if it heartbeated within this window. Agents beat every 60s.</p>
                            <x-input-error for="online_threshold_seconds" class="mt-1" />
                        </div>
                        <div>
                            <x-label for="offline_after_minutes" value="Offline alert after (minutes)" />
                            <x-input id="offline_after_minutes" type="number" min="5" max="10080"
                                     class="mt-1 block w-32" wire:model="offline_after_minutes" />
                            <p class="mt-1 text-xs text-slate-500">Silence this long raises an “agent offline” notification (once per outage).</p>
                            <x-input-error for="offline_after_minutes" class="mt-1" />
                        </div>
                    </div>
                </div>

                <div class="border-t pt-5">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Deployments & policies</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-label for="default_max_attempts" value="Job retry attempts" />
                            <x-input id="default_max_attempts" type="number" min="1" max="10"
                                     class="mt-1 block w-32" wire:model="default_max_attempts" />
                            <p class="mt-1 text-xs text-slate-500">How many times a failing job re-queues before it is terminal.</p>
                            <x-input-error for="default_max_attempts" class="mt-1" />
                        </div>
                        <div>
                            <x-label for="failure_backoff_hours" value="Policy failure backoff (hours)" />
                            <x-input id="failure_backoff_hours" type="number" min="1" max="168"
                                     class="mt-1 block w-32" wire:model="failure_backoff_hours" />
                            <p class="mt-1 text-xs text-slate-500">After a failed or cancelled remediation, policies wait this long before trying again.</p>
                            <x-input-error for="failure_backoff_hours" class="mt-1" />
                        </div>
                    </div>
                </div>

                <div class="border-t pt-5">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Data retention</h3>
                    <div class="max-w-sm">
                        <x-label for="activity_retention_days" value="Keep activity log (days)" />
                        <x-input id="activity_retention_days" type="number" min="7" max="3650"
                                 class="mt-1 block w-32" wire:model="activity_retention_days" />
                        <p class="mt-1 text-xs text-slate-500">Older audit entries are pruned nightly at 03:30.</p>
                        <x-input-error for="activity_retention_days" class="mt-1" />
                    </div>
                </div>

                <div class="border-t pt-5">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">Security</h3>
                    <div class="max-w-sm">
                        <x-label for="require_two_factor" value="Require two-factor authentication" />
                        <select id="require_two_factor" wire:model="require_two_factor"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <option value="off">Off — 2FA stays optional</option>
                            <option value="staff">Staff — everyone except client-portal users</option>
                            <option value="all">Everyone — including client users</option>
                        </select>
                        <p class="mt-1 text-xs text-slate-500">
                            Users without 2FA are sent to their profile to enrol before they can continue.
                            Nobody is signed out — enrolment happens on their next page load.
                        </p>
                        <x-input-error for="require_two_factor" class="mt-1" />
                    </div>
                </div>

                <div class="flex justify-end border-t pt-4">
                    <x-button>Save settings</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
