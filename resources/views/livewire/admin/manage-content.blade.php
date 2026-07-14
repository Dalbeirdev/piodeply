<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Website content') }}</h2>
            <a href="{{ url('/') }}" target="_blank"
               class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                View site ↗
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                Edit the public marketing site copy here — headlines, pricing intro, contact details and more.
                Leave a field blank to fall back to the shipped default. The company name and logo come from
                <a href="{{ route('admin.settings') }}" class="pd-link">Settings</a>.
            </div>

            <form wire:submit="save" class="pd-card p-6 space-y-6">
                @foreach ($schema as $group => $fields)
                    <div class="border-t first:border-t-0 pt-5 first:pt-0">
                        <h3 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-3">{{ $group }}</h3>
                        <div class="space-y-4">
                            @foreach ($fields as [$key, $label, $type, $default])
                                @php $alias = \App\Livewire\Admin\ManageContent::alias($key); @endphp
                                <div>
                                    <x-label :for="$alias" :value="$label" />
                                    @if ($type === 'textarea')
                                        <textarea id="{{ $alias }}" rows="3" wire:model="values.{{ $alias }}"
                                                  class="mt-1 block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm"></textarea>
                                    @else
                                        <x-input id="{{ $alias }}" type="text" class="mt-1 block w-full"
                                                 wire:model="values.{{ $alias }}" placeholder="{{ $default }}" />
                                    @endif
                                    <x-input-error for="values.{{ $alias }}" class="mt-1" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="flex justify-between items-center border-t pt-4">
                    <button type="button" wire:click="resetToDefaults"
                            wire:confirm="Reset all website copy to the shipped defaults?"
                            class="text-xs text-slate-500 hover:text-red-600">
                        Reset to defaults
                    </button>
                    <x-button>Save content</x-button>
                </div>
            </form>
        </div>
    </div>
</div>
