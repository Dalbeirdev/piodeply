<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $client ? 'Edit ' . $client->company_name : 'New Client' }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <form wire:submit="save" class="bg-white shadow-xl sm:rounded-lg p-6 space-y-6">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-label for="company_name" value="Company name" />
                        <x-input id="company_name" type="text" class="mt-1 block w-full" wire:model="company_name" required />
                        <x-input-error for="company_name" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="email" value="Email" />
                        <x-input id="email" type="email" class="mt-1 block w-full" wire:model="email" required />
                        <x-input-error for="email" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="phone" value="Phone" />
                        <x-input id="phone" type="text" class="mt-1 block w-full" wire:model="phone" />
                        <x-input-error for="phone" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="timezone" value="Timezone" />
                        <select id="timezone" wire:model="timezone"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            @foreach ($timezones as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="timezone" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="status" value="Status" />
                        <select id="status" wire:model="status"
                                class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            @foreach ($statuses as $statusOption)
                                <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="status" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="logo" value="Logo (PNG/JPG, max 1 MB)" />
                        <input id="logo" type="file" wire:model="logo" accept="image/*"
                               class="mt-1 block w-full text-sm text-gray-600 file:mr-2 file:py-1.5 file:px-3 file:rounded file:border-0 file:bg-gray-100">
                        <x-input-error for="logo" class="mt-1" />
                        @if ($client?->logoUrl())
                            <img src="{{ $client->logoUrl() }}" alt="Current logo" class="mt-2 h-10 rounded">
                        @endif
                    </div>
                </div>

                <fieldset class="border-t pt-4">
                    <legend class="text-sm font-semibold text-gray-700">Address</legend>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                        <div class="md:col-span-2">
                            <x-label for="address_line1" value="Address line 1" />
                            <x-input id="address_line1" type="text" class="mt-1 block w-full" wire:model="address_line1" />
                        </div>
                        <div class="md:col-span-2">
                            <x-label for="address_line2" value="Address line 2" />
                            <x-input id="address_line2" type="text" class="mt-1 block w-full" wire:model="address_line2" />
                        </div>
                        <div><x-label for="city" value="City" /><x-input id="city" type="text" class="mt-1 block w-full" wire:model="city" /></div>
                        <div><x-label for="state" value="State / Region" /><x-input id="state" type="text" class="mt-1 block w-full" wire:model="state" /></div>
                        <div><x-label for="postal_code" value="Postal code" /><x-input id="postal_code" type="text" class="mt-1 block w-full" wire:model="postal_code" /></div>
                        <div><x-label for="country" value="Country" /><x-input id="country" type="text" class="mt-1 block w-full" wire:model="country" /></div>
                    </div>
                </fieldset>

                <fieldset class="border-t pt-4">
                    <legend class="text-sm font-semibold text-gray-700">Billing</legend>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-2">
                        <div>
                            <x-label for="billing_email" value="Billing email" />
                            <x-input id="billing_email" type="email" class="mt-1 block w-full" wire:model="billing_email" />
                            <x-input-error for="billing_email" class="mt-1" />
                        </div>
                        <div>
                            <x-label for="billing_tax_id" value="Tax / VAT ID" />
                            <x-input id="billing_tax_id" type="text" class="mt-1 block w-full" wire:model="billing_tax_id" />
                        </div>
                        <div class="md:col-span-2">
                            <x-label for="billing_address" value="Billing address" />
                            <x-input id="billing_address" type="text" class="mt-1 block w-full" wire:model="billing_address" />
                        </div>
                    </div>
                </fieldset>

                <fieldset class="border-t pt-4">
                    <div class="flex items-center justify-between">
                        <legend class="text-sm font-semibold text-gray-700">Contacts</legend>
                        <button type="button" wire:click="addContact"
                                class="text-sm font-semibold text-indigo-600 hover:underline">+ Add contact</button>
                    </div>
                    <div class="space-y-3 mt-2">
                        @forelse ($contacts as $index => $contact)
                            <div class="grid grid-cols-1 md:grid-cols-5 gap-2 items-start border rounded-md p-3" wire:key="contact-{{ $index }}">
                                <div>
                                    <x-input type="text" class="block w-full text-sm" placeholder="Name *"
                                             aria-label="Contact name" wire:model="contacts.{{ $index }}.name" />
                                    <x-input-error for="contacts.{{ $index }}.name" class="mt-1" />
                                </div>
                                <x-input type="text" class="block w-full text-sm" placeholder="Title"
                                         aria-label="Contact title" wire:model="contacts.{{ $index }}.title" />
                                <div>
                                    <x-input type="email" class="block w-full text-sm" placeholder="Email"
                                             aria-label="Contact email" wire:model="contacts.{{ $index }}.email" />
                                    <x-input-error for="contacts.{{ $index }}.email" class="mt-1" />
                                </div>
                                <x-input type="text" class="block w-full text-sm" placeholder="Phone"
                                         aria-label="Contact phone" wire:model="contacts.{{ $index }}.phone" />
                                <div class="flex items-center gap-3 pt-1.5">
                                    <label class="flex items-center gap-1 text-xs text-gray-600">
                                        <input type="checkbox" wire:model="contacts.{{ $index }}.is_primary" class="rounded border-gray-300">
                                        Primary
                                    </label>
                                    <button type="button" wire:click="removeContact({{ $index }})"
                                            class="text-xs text-red-600 hover:underline">Remove</button>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-500">No contacts yet.</p>
                        @endforelse
                    </div>
                </fieldset>

                <div>
                    <x-label for="notes" value="Notes" />
                    <textarea id="notes" rows="3" wire:model="notes"
                              class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                </div>

                <div class="flex justify-end gap-3 border-t pt-4">
                    <a href="{{ route('clients.index') }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50">
                        Cancel
                    </a>
                    <x-button>{{ $client ? 'Save changes' : 'Create client' }}</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
