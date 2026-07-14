<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ $package ? 'Edit ' . $package->name : 'New Package' }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <form wire:submit="save" class="pd-card p-6 space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <x-label for="name" value="Name" />
                        <x-input id="name" type="text" class="mt-1 block w-full" wire:model="name" required />
                        <x-input-error for="name" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="package_category_id" value="Category" />
                        <select id="package_category_id" wire:model="package_category_id"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <option value="">— select —</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="package_category_id" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="vendor" value="Vendor" />
                        <x-input id="vendor" type="text" class="mt-1 block w-full" wire:model="vendor" />
                    </div>
                    <div>
                        <x-label for="license" value="License" />
                        <x-input id="license" type="text" class="mt-1 block w-full" wire:model="license" placeholder="e.g. GPL-3.0, Commercial" />
                    </div>
                    <div class="md:col-span-2">
                        <x-label for="homepage" value="Homepage" />
                        <x-input id="homepage" type="url" class="mt-1 block w-full" wire:model="homepage" placeholder="https://…" />
                        <x-input-error for="homepage" class="mt-1" />
                    </div>
                    <div>
                        <x-label for="installer_type" value="Installer type" />
                        <select id="installer_type" wire:model.live="installer_type"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-label for="architecture" value="Architecture" />
                        <select id="architecture" wire:model="architecture"
                                class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            @foreach ($architectures as $arch)
                                <option value="{{ $arch->value }}">{{ $arch->value }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($installer_type === 'winget')
                        <div class="md:col-span-2">
                            <x-label for="winget_id" value="winget ID" />
                            <x-input id="winget_id" type="text" class="mt-1 block w-full font-mono" wire:model="winget_id" placeholder="e.g. Google.Chrome" />
                            <x-input-error for="winget_id" class="mt-1" />
                        </div>
                    @elseif ($installer_type === 'choco')
                        <div class="md:col-span-2">
                            <x-label for="choco_id" value="Chocolatey ID" />
                            <x-input id="choco_id" type="text" class="mt-1 block w-full font-mono" wire:model="choco_id" placeholder="e.g. googlechrome" />
                            <x-input-error for="choco_id" class="mt-1" />
                        </div>
                    @else
                        <div class="md:col-span-2 rounded-md bg-blue-50 border border-blue-200 p-3 text-sm text-blue-700">
                            Binary installer — add versions with a download URL and SHA-256 on the package page after saving.
                        </div>
                    @endif
                </div>

                <div>
                    <x-label for="description" value="Description" />
                    <textarea id="description" rows="3" wire:model="description"
                              class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm"></textarea>
                </div>

                <div class="flex justify-end gap-3 border-t pt-4">
                    <a href="{{ route('packages.index') }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                        Cancel
                    </a>
                    <x-button>{{ $package ? 'Save changes' : 'Create package' }}</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
