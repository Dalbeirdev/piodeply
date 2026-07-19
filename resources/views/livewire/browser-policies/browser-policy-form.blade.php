<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ $policy ? 'Edit browser policy' : 'New Browser Policy' }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <div>
                    <x-label for="name" value="Policy name" />
                    <x-input id="name" type="text" class="mt-1 block w-full" wire:model="name"
                             placeholder="e.g. Block private browsing — Demo Fleet" />
                    <x-input-error for="name" class="mt-1" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-label for="scope_type" value="Assign to" />
                        <select id="scope_type" wire:model.live="scope_type"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <option value="all">All machines (whole instance)</option>
                            <option value="client">Client — every project it has</option>
                            <option value="project">Project</option>
                            <option value="group">Device group</option>
                            <option value="computer">Single computer</option>
                        </select>
                        <x-input-error for="scope_type" class="mt-1" />
                    </div>
                    <div>
                        @if ($scope_type !== 'all')
                            <x-label for="scope_id" value="Target" />
                            <select id="scope_id" wire:model="scope_id"
                                    class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                <option value="">— select —</option>
                                @foreach ($scopeOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            <x-input-error for="scope_id" class="mt-1" />
                        @else
                            <x-label value="Target" />
                            <p class="mt-2.5 text-sm text-slate-500">Every enrolled machine, present and future.</p>
                        @endif
                    </div>
                </div>
                <p class="-mt-2 text-xs text-slate-500">
                    When policies of the same type overlap, the most specific wins:
                    <b>Computer&nbsp;›&nbsp;Group&nbsp;›&nbsp;Project&nbsp;›&nbsp;Client&nbsp;›&nbsp;All</b>.
                    Individual machines can still be excluded from the policy page after creating.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-label for="type" value="Restriction" />
                        <select id="type" wire:model.live="type"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            @foreach ($typesByCategory as $category => $categoryTypes)
                                <optgroup label="{{ $category }}">
                                    @foreach ($categoryTypes as $typeOption)
                                        <option value="{{ $typeOption->value }}">{{ $typeOption->label() }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <x-input-error for="type" class="mt-1" />
                    </div>
                    <div>
                        @if ($selectedType?->valueKind() === null)
                            <x-label for="action" value="Action" />
                            <select id="action" wire:model="action"
                                    class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                <option value="disable">Disable — block the feature</option>
                                <option value="enable">Enable — explicitly allow it</option>
                            </select>
                            <x-input-error for="action" class="mt-1" />
                        @else
                            {{-- Value policies always enforce; removal = delete the policy. --}}
                            <x-label value="Action" />
                            <p class="mt-2.5 text-sm text-slate-500">Enforced while active — delete or deactivate the policy to roll it back.</p>
                        @endif
                    </div>
                </div>

                @if ($selectedType?->valueKind() === 'url')
                    <div>
                        <x-label for="value_url" value="URL to enforce" />
                        <x-input id="value_url" type="url" class="mt-1 block w-full" wire:model="value_url"
                                 placeholder="https://intranet.example.com" />
                        <x-input-error for="value_url" class="mt-1" />
                    </div>
                @elseif ($selectedType?->valueKind() === 'ids')
                    <div>
                        <x-label for="value_ids" value="Extension IDs (one per line)" />
                        <textarea id="value_ids" rows="3" wire:model="value_ids"
                                  placeholder="cjpalhdlnbpafiamejdnhcphjbkeiagm"
                                  class="mt-1 block w-full font-mono text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm"></textarea>
                        <p class="mt-1 text-xs text-slate-500">The 32-letter ID from the Chrome Web Store URL. Extensions install silently and cannot be removed by users.</p>
                        <x-input-error for="value_ids" class="mt-1" />
                    </div>
                @endif

                @if ($selectedType)
                    <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm">
                        <p class="text-slate-700">{{ $selectedType->description() }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span>
                                <span class="font-semibold text-slate-600">Supported:</span>
                                {{ collect($selectedType->supportedBrowsers())->map->label()->implode(', ') ?: 'none' }}
                            </span>
                            <span><span class="font-semibold text-slate-600">Requires restart:</span> {{ $selectedType->requiresRestart() ? 'Yes' : 'No' }}</span>
                            <span><span class="font-semibold text-slate-600">Platform:</span> {{ $selectedType->platform() }}</span>
                        </div>
                    </div>
                @endif

                <div>
                    <x-label value="Browsers" />
                    <div class="mt-2 flex flex-wrap gap-4">
                        <label class="flex items-center gap-1.5 text-sm font-medium text-slate-700">
                            <x-checkbox value="all" wire:model.live="browsers" /> All browsers
                        </label>
                        @foreach ($allBrowsers as $browser)
                            <label class="flex items-center gap-1.5 text-sm text-slate-700 {{ in_array('all', $browsers, true) ? 'opacity-40' : '' }}">
                                <x-checkbox value="{{ $browser->value }}" wire:model.live="browsers"
                                            :disabled="in_array('all', $browsers, true)" />
                                {{ $browser->label() }}
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Opera has no enterprise policy support — Opera targets report “unsupported”.</p>
                    <x-input-error for="browsers" class="mt-1" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-label for="status" value="Status" />
                        <select id="status" wire:model="status"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <x-input-error for="status" class="mt-1" />
                    </div>
                </div>

                <div>
                    <x-label for="description" value="Description" />
                    <textarea id="description" rows="2" wire:model="description"
                              class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm"></textarea>
                    <x-input-error for="description" class="mt-1" />
                </div>

                <div class="rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                    Applied via <code class="font-mono text-xs">HKLM\SOFTWARE\Policies</code> (Chrome/Edge/Brave)
                    and <code class="font-mono text-xs">distribution\policies.json</code> (Firefox). Browsers
                    already running pick the change up on restart — those machines show “pending restart”
                    until then. Deleting or deactivating the policy rolls the settings back.
                </div>

                <div class="flex justify-end gap-3 border-t pt-4">
                    <a href="{{ route('browser-policies.index') }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Cancel
                    </a>
                    <x-button>{{ $policy ? 'Save changes' : 'Create policy' }}</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
