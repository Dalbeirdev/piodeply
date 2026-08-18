<div>
    <x-slot name="header">
        <div>
            <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Needs attention') }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">Deployment failures the agent gave up retrying, grouped by cause.</p>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">

            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">
                    {{ session('status') }}
                </div>
            @endif

            <div class="pd-card">
                <ul class="divide-y divide-slate-100">
                    @forelse ($causes as $cause)
                        @php
                            $tone = $cause['kind'] === \App\Enums\FailureKind::Machine
                                ? ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700']
                                : ['bg' => 'bg-red-50', 'border' => 'border-red-200', 'text' => 'text-red-700'];
                        @endphp
                        <li class="p-5">
                            <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
                                <div class="min-w-[16rem] grow">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-xs font-semibold rounded-full px-2.5 py-1 border {{ $tone['bg'] }} {{ $tone['border'] }} {{ $tone['text'] }}">
                                            {{ $cause['kind']->label() }}
                                        </span>
                                        <span class="text-xs text-slate-400">owner: {{ $cause['owner'] }}</span>
                                    </div>

                                    <p class="font-semibold text-slate-900 mt-1.5">
                                        @if ($cause['package'])
                                            <a href="{{ route('packages.show', $cause['package']) }}" class="pd-link">{{ $cause['package']->name }}</a>
                                        @else
                                            Unknown package
                                        @endif
                                        @if ($cause['computer'])
                                            <span class="font-normal text-slate-500">on</span>
                                            <a href="{{ route('computers.show', $cause['computer']) }}" class="pd-link">{{ $cause['computer']->hostname }}</a>
                                        @endif
                                    </p>

                                    <p class="text-sm text-slate-600 mt-1">
                                        {{ $cause['failure_reason'] ?? ('exit code '.($cause['exit_code'] ?? 'unknown')) }}
                                    </p>
                                    @if ($cause['hint'])
                                        <p class="text-sm text-slate-500 mt-1.5 max-w-2xl">{{ $cause['hint'] }}</p>
                                    @endif

                                    <p class="text-xs text-slate-400 mt-2">
                                        {{ $cause['affected_computers'] }} {{ Str::plural('machine', $cause['affected_computers']) }} affected
                                        <span class="mx-1">·</span>first seen {{ $cause['first_seen']->diffForHumans() }}
                                        <span class="mx-1">·</span>last seen {{ $cause['last_seen']->diffForHumans() }}
                                    </p>
                                </div>

                                @can('manage', $cause['latest_job'])
                                    <div class="shrink-0">
                                        <button type="button"
                                                wire:click="dismiss('{{ $cause['cause_key'] }}', {{ $cause['latest_job']->id }})"
                                                wire:confirm="Mark this handled? It reappears automatically if it fails again."
                                                class="text-sm font-medium text-teal-700 hover:text-teal-800">
                                            Mark handled
                                        </button>
                                    </div>
                                @endcan
                            </div>
                        </li>
                    @empty
                        <li class="px-6 py-12 text-center">
                            <p class="text-slate-500">Nothing needs attention.</p>
                            <p class="text-xs text-slate-400 mt-1">Failures the agent gives up on appear here, grouped by cause — not one row per machine.</p>
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>
