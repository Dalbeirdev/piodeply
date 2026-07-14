<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Notifications') }}</h2>
            <button type="button" wire:click="create"
                    class="inline-flex items-center px-4 py-2 bg-teal-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-500">
                Add channel
            </button>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                Channels receive alerts for the events they subscribe to. <strong>Email</strong> uses the
                configured mailer; <strong>webhooks</strong> POST JSON with a Slack/Discord-compatible
                <code class="font-mono text-xs">text</code> field, so a Slack incoming-webhook URL works as-is.
            </div>

            @if ($showForm)
                <form wire:submit="save" class="pd-card p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider">
                        {{ $editingId ? 'Edit channel' : 'New channel' }}
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <x-label for="name" value="Name" />
                            <x-input id="name" type="text" class="mt-1 block w-full" wire:model="name" placeholder="Ops alerts" />
                            <x-input-error for="name" class="mt-1" />
                        </div>
                        <div>
                            <x-label for="type" value="Type" />
                            <select id="type" wire:model.live="type"
                                    class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                <option value="email">Email</option>
                                <option value="webhook">Webhook</option>
                            </select>
                        </div>
                        <div>
                            <x-label for="destination" value="{{ $type === 'email' ? 'Email address' : 'Webhook URL' }}" />
                            <x-input id="destination" type="text" class="mt-1 block w-full" wire:model="destination"
                                     placeholder="{{ $type === 'email' ? 'ops@techpio.com' : 'https://hooks.slack.com/services/…' }}" />
                            <x-input-error for="destination" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <x-label value="Events" />
                        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @foreach ($allEvents as $eventKey => $eventLabel)
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <x-checkbox value="{{ $eventKey }}" wire:model="events" />
                                    {{ $eventLabel }}
                                    <span class="text-xs text-slate-400 font-mono">{{ $eventKey }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error for="events" class="mt-1" />
                    </div>
                    <div class="flex justify-end gap-3 border-t pt-4">
                        <button type="button" wire:click="$set('showForm', false)"
                                class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                            Cancel
                        </button>
                        <x-button>{{ $editingId ? 'Save changes' : 'Create channel' }}</x-button>
                    </div>
                </form>
            @endif

            <div class="pd-card">
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="pd-th">Channel</th>
                            <th class="pd-th">Destination</th>
                            <th class="pd-th">Events</th>
                            <th class="pd-th">Status</th>
                            <th class="pd-th">Last sent</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-100">
                        @forelse ($channels as $channel)
                            <tr @class(['opacity-60' => ! $channel->is_active])>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    <span class="font-medium text-slate-800">{{ $channel->name }}</span>
                                    <p class="text-xs text-slate-400 uppercase">{{ $channel->type }}</p>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600 text-sm max-w-xs truncate" title="{{ $channel->destination }}">
                                    {{ $channel->destination }}
                                </td>
                                <td class="px-6 py-3 text-sm text-slate-600">
                                    @foreach ($channel->events as $eventKey)
                                        <span class="inline-block text-xs bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5 mb-0.5">{{ $eventKey }}</span>
                                    @endforeach
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap">
                                    @if (! $channel->is_active)
                                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-slate-100 text-slate-500 border-slate-200">Disabled</span>
                                    @elseif ($channel->last_error)
                                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-red-50 text-red-700 border-red-200"
                                              title="{{ $channel->last_error }}">Error</span>
                                    @else
                                        <span class="text-xs font-semibold rounded-full px-2 py-0.5 border bg-green-50 text-green-700 border-green-200">Active</span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 text-sm">
                                    {{ $channel->last_sent_at?->diffForHumans() ?? 'never' }}
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm space-x-1">
                                    <x-icon-button icon="play" label="Send test" wire:click="sendTest({{ $channel->id }})" wire:loading.attr="disabled" />
                                    <x-icon-button icon="power" variant="amber" label="{{ $channel->is_active ? 'Disable' : 'Enable' }}" wire:click="toggle({{ $channel->id }})" />
                                    <x-icon-button icon="edit" label="Edit" wire:click="edit({{ $channel->id }})" />
                                    <x-icon-button icon="delete" variant="danger" label="Delete"
                                                   wire:click="delete({{ $channel->id }})"
                                                   wire:confirm="Delete this channel?" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">
                                No channels yet. Add an email address or a Slack/Teams webhook to get alerted about
                                failed deployments, offline agents and compliance drift.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>
</div>
