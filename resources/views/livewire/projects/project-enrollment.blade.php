<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Enrol machines') }}
            <span class="text-slate-400 font-normal">— {{ $project->name }}</span>
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">

            {{-- The key is hashed server-side, so there is nothing to pre-fill.
                 Say so plainly rather than leaving the operator wondering. --}}
            <div class="pd-card p-6">
                <label for="apiKey" class="block text-sm font-medium text-slate-700">Project API key</label>
                <p class="text-xs text-slate-500 mt-1">
                    Paste the key for <strong>{{ $project->name }}</strong> and it drops into the scripts below.
                    It was shown once when the project was created — PioDeploy stores only a hash and cannot show it
                    again. Lost it? <a href="{{ route('projects.edit', $project) }}" class="pd-link">Rotate the key</a>,
                    which invalidates the old one.
                </p>
                <input id="apiKey" type="text" wire:model.live.debounce.400ms="apiKey"
                       placeholder="{{ \App\Services\EnrollmentScriptService::KEY_PLACEHOLDER }}" autocomplete="off"
                       class="mt-2 block w-full font-mono text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">

                @if ($keyRejected)
                    <p class="mt-2 text-xs text-red-700 bg-red-50 border border-red-200 rounded-md p-2">
                        That does not look like a project key. A key is 8–128 characters of letters, numbers,
                        <code>-</code> and <code>_</code> — nothing else. The scripts below keep the placeholder
                        rather than embed something unexpected in a script that runs as SYSTEM on every machine
                        it reaches. If you pasted this from an email or a message, check where it came from.
                    </p>
                @elseif (! $hasKey)
                    <p class="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-2">
                        No key entered — the scripts below carry a placeholder and will not run until you replace it.
                    </p>
                @endif
            </div>

            <div class="pd-card p-6 space-y-4">
                <div class="flex flex-wrap gap-2" role="tablist" aria-label="Enrollment method">
                    @foreach ($methods as $key => $method)
                        <button type="button" wire:click="select('{{ $key }}')" role="tab"
                                aria-selected="{{ $selected === $key ? 'true' : 'false' }}"
                                class="px-3 py-1.5 rounded-full text-sm border transition
                                       {{ $selected === $key
                                            ? 'bg-teal-700 border-teal-700 text-white'
                                            : 'bg-white border-slate-300 text-slate-600 hover:border-teal-400' }}">
                            {{ $method['label'] }}
                        </button>
                    @endforeach
                </div>

                @if ($selected === 'gpo')
                    <div class="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-md p-3">
                        <p class="font-medium text-slate-700">Runs as SYSTEM at boot — nobody has to log in.</p>
                        <p class="mt-1">
                            Save it to <code class="font-mono text-xs">\\yourdomain\NETLOGON\PioDeploy\</code>, then in
                            <strong>gpmc.msc</strong>: create a GPO on the target OU → Edit → Computer Configuration →
                            Policies → Windows Settings → Scripts (Startup/Shutdown) → <strong>Startup</strong> →
                            PowerShell Scripts → Add. Then <code class="font-mono text-xs">gpupdate /force</code> and reboot.
                        </p>
                        <p class="mt-1 text-slate-500">
                            Safe every boot: it exits immediately when the agent is already at
                            {{ \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION }} or newer, and upgrades it when it is not.
                        </p>
                    </div>
                @endif

                <div class="relative">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-mono text-slate-500">{{ $current['filename'] }}</span>
                        <button type="button" class="text-xs pd-link"
                                x-data
                                x-on:click="
                                    navigator.clipboard.writeText($refs.script.innerText);
                                    $el.textContent = 'Copied';
                                    setTimeout(() => $el.textContent = 'Copy', 1500);
                                ">Copy</button>
                    </div>
                    <pre class="bg-slate-900 text-slate-100 rounded-lg p-4 overflow-x-auto text-xs leading-relaxed"><code x-ref="script">{{ $current['body'] }}</code></pre>
                </div>
            </div>

            <p class="text-xs text-slate-500">
                Every method installs the same agent and enrols it into <strong>{{ $project->name }}</strong>.
                Machines appear under <a href="{{ route('computers.index') }}" class="pd-link">Computers</a> within a
                minute of the agent starting.
            </p>
        </div>
    </div>
</div>
