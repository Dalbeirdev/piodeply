<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Browser Policy Templates') }}</h2>
            <a href="{{ route('browser-policies.index') }}"
               class="inline-flex items-center px-4 py-2 bg-white border border-slate-300 rounded-md font-semibold text-xs text-slate-700 uppercase tracking-widest hover:bg-slate-50">
                ← All policies
            </a>
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
                A template bundles several browser policies. Applying one creates the individual policies
                on the chosen project — types the project already has are skipped, never overwritten — and
                each created policy can then be edited or excluded per machine as usual.
            </div>

            {{-- Template cards --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ($templates as $template)
                    <div class="pd-card p-5 flex flex-col" wire:key="tpl-{{ $template['key'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-semibold text-slate-800">{{ $template['name'] }}</h3>
                            <span class="text-xs rounded-full px-2 py-0.5 border {{ $template['custom'] ? 'bg-sky-50 text-sky-700 border-sky-200' : 'bg-slate-100 text-slate-600 border-slate-200' }}">
                                {{ $template['custom'] ? 'Custom' : 'Built-in' }}
                            </span>
                        </div>
                        <p class="text-sm text-slate-500 mt-1">{{ $template['description'] }}</p>

                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach (array_slice($template['types'], 0, 8) as $type)
                                <span class="text-xs rounded-full bg-slate-100 text-slate-600 px-2 py-0.5">{{ $type->label() }}</span>
                            @endforeach
                            @if (count($template['types']) > 8)
                                <span class="text-xs text-slate-400 px-1 py-0.5">+{{ count($template['types']) - 8 }} more</span>
                            @endif
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between gap-2">
                            <span class="text-xs text-slate-400">{{ count($template['types']) }} {{ Str::plural('policy', count($template['types'])) }}</span>
                            <div class="flex items-center gap-2">
                                @if ($template['custom'])
                                    <button type="button" wire:click="deleteTemplate({{ $template['model']->id }})"
                                            wire:confirm="Delete the “{{ $template['name'] }}” template? Policies it already created stay in place."
                                            class="text-xs font-semibold text-red-600 hover:text-red-700">Delete</button>
                                @endif
                                <button type="button" wire:click="startApply('{{ $template['key'] }}')"
                                        class="inline-flex items-center px-3 py-1.5 bg-teal-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-500">
                                    Apply…
                                </button>
                            </div>
                        </div>

                        @if ($applyKey === $template['key'])
                            <div class="mt-3 rounded-md bg-teal-50 border border-teal-200 p-3 space-y-2">
                                <label class="block text-sm font-medium text-slate-700">Apply to project</label>
                                <select wire:model="applyProjectId"
                                        class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                                    <option value="">— select project —</option>
                                    @foreach ($projects as $project)
                                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error for="applyProjectId" />
                                <div class="flex justify-end gap-2">
                                    <button type="button" wire:click="cancelApply" class="text-xs font-semibold text-slate-500 hover:text-slate-700">Cancel</button>
                                    <button type="button" wire:click="apply"
                                            class="inline-flex items-center px-3 py-1.5 bg-teal-600 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-500">
                                        Apply template
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Save a project's policies as a custom template --}}
            <div class="pd-card p-5">
                <h3 class="font-semibold text-slate-800">Save a project as a template</h3>
                <p class="text-sm text-slate-500 mt-1">Capture everything a project currently enforces and reuse it elsewhere.</p>
                <form wire:submit="capture" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 items-start">
                    <div>
                        <select wire:model="captureProjectId" aria-label="Source project"
                                class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                            <option value="">— source project —</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}">{{ $project->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error for="captureProjectId" class="mt-1" />
                    </div>
                    <div>
                        <input type="text" wire:model="captureName" placeholder="Template name"
                               class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                        <x-input-error for="captureName" class="mt-1" />
                    </div>
                    <div class="flex gap-2">
                        <input type="text" wire:model="captureDescription" placeholder="Description (optional)"
                               class="block w-full border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm text-sm">
                        <x-button type="submit" class="whitespace-nowrap">Save</x-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
