<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-slate-900 leading-tight">Policy templates</h2>
            <a href="{{ route('policies.index') }}" class="text-sm pd-action">← Policies</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-5">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <p class="text-sm text-slate-500">
                A template is a ready-made set of software policies. Pick a project, click Apply, and every
                policy in the kit is created at once — apps already covered are skipped, never duplicated.
                Missing catalogue packages are created automatically from their winget identity.
            </p>

            @foreach ($templates as $template)
                <div class="pd-card p-5 space-y-3" wire:key="tpl-{{ $template->id }}">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800">
                                {{ $template->name }}
                                @if ($template->is_builtin)
                                    <span class="ml-1 pd-badge pd-badge-sky">Built-in</span>
                                @endif
                            </h3>
                            <p class="text-xs text-slate-500 mt-0.5">{{ $template->description }}</p>
                        </div>
                        @if ($isStaff && $canManage && ! $template->is_builtin)
                            <button type="button" wire:click="delete({{ $template->id }})"
                                wire:confirm="Delete template “{{ $template->name }}”? Policies it already created on projects are untouched."
                                class="text-xs text-rose-600 hover:text-rose-700 font-medium shrink-0">Delete</button>
                        @endif
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($template->items->groupBy('package_name') as $app => $items)
                            <span class="text-xs bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5"
                                  title="{{ $items->map(fn ($i) => $i->action->value)->implode(' + ') }} · {{ $items->first()->frequency->value }}">
                                {{ $app }}
                                <span class="text-slate-400">({{ $items->map(fn ($i) => $i->action->value)->implode('+') }})</span>
                            </span>
                        @endforeach
                    </div>

                    @if ($canManage && $projects->isNotEmpty())
                        <div class="flex items-center gap-2 pt-1">
                            <select wire:model="applyProject.{{ $template->id }}"
                                    class="block w-64 text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                                <option value="">Choose a project…</option>
                                @foreach ($projects as $project)
                                    <option value="{{ $project->id }}">{{ $project->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="apply({{ $template->id }})"
                                    class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                                Apply
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach

            @if ($isStaff && $canManage)
                <div class="pd-card p-5 space-y-3">
                    <h3 class="text-sm font-semibold text-slate-800">Save a project's policies as a template</h3>
                    <p class="text-xs text-slate-500">
                        Snapshot an existing project's winget policies into a reusable kit (policies on
                        non-winget packages are skipped — only the portable winget identity travels).
                    </p>
                    <div class="grid sm:grid-cols-3 gap-2">
                        <select wire:model="sourceProjectId"
                                class="block w-full text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                            <option value="">Source project…</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}">{{ $project->name }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="newName" placeholder="Template name"
                               class="block w-full text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                        <input type="text" wire:model="newDescription" placeholder="Description (optional)"
                               class="block w-full text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">
                    </div>
                    @error('newName')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    @error('sourceProjectId')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    <button type="button" wire:click="saveAsTemplate"
                            class="inline-flex items-center px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                        Save template
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
