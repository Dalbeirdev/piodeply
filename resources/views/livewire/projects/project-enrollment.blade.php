<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">
            {{ __('Enrol machines') }}
            <span class="text-slate-400 font-normal">— {{ $project->name }}</span>
        </h2>
    </x-slot>

    {{-- The key is deliberately outside Livewire. Public properties are
         serialised into the page and posted on every update, which is no place
         for a credential that installs software as SYSTEM across a fleet. The
         script renders with a placeholder and the browser swaps it in; the key
         never leaves this tab. --}}
    <div class="py-12"
         x-data="{
             key: '',
             get entered() { return this.key.trim() !== '' },
             get valid() { return new RegExp(@js($keyPattern)).test(this.key.trim()) },
             fill(body) {
                 return (this.entered && this.valid)
                     ? body.replaceAll(@js($placeholder), this.key.trim())
                     : body;
             },
             copy(el) {
                 navigator.clipboard.writeText(this.fill(@js($current['body'])));
                 el.textContent = 'Copied';
                 setTimeout(() => el.textContent = 'Copy', 1500);
             },
         }">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">

            <div class="pd-card p-6">
                <label for="apiKey" class="block text-sm font-medium text-slate-700">Project API key</label>
                <p class="text-xs text-slate-500 mt-1">
                    Paste the key for <strong>{{ $project->name }}</strong> and it drops into the scripts below.
                    It was shown once when the project was created — PioDeploy stores only a hash and cannot show it
                    again. Lost it? <a href="{{ route('projects.edit', $project) }}" class="pd-link">Rotate the key</a>,
                    which invalidates the old one.
                </p>
                <input id="apiKey" type="text" x-model="key" autocomplete="off" spellcheck="false"
                       placeholder="{{ $placeholder }}"
                       class="mt-2 block w-full font-mono text-sm border-slate-300 focus:border-teal-500 focus:ring-teal-500 rounded-md shadow-sm">

                <p x-cloak x-show="entered && ! valid"
                   class="mt-2 text-xs text-red-700 bg-red-50 border border-red-200 rounded-md p-2">
                    That does not look like a project key. A key is 8–128 characters of letters, numbers,
                    <code>-</code> and <code>_</code> — nothing else. The script below keeps the placeholder rather
                    than embed something unexpected in a script that runs as SYSTEM on every machine it reaches.
                    If this came from an email or a message, check where it came from.
                </p>
                <p x-cloak x-show="! entered"
                   class="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-md p-2">
                    No key entered — the scripts below carry a placeholder and will not run until you replace it.
                </p>
                <p x-cloak x-show="entered && valid" class="mt-2 text-xs text-slate-400">
                    Filled in below. Your key stays in this browser tab — it is never sent to the server.
                </p>
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

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-xs font-mono text-slate-500">{{ $current['filename'] }}</span>
                        <button type="button" class="text-xs pd-link" x-on:click="copy($el)">Copy</button>
                    </div>
                    <pre class="bg-slate-900 text-slate-100 rounded-lg p-4 overflow-x-auto text-xs leading-relaxed"
                         x-text="fill(@js($current['body']))">{{ $current['body'] }}</pre>
                </div>
            </div>

            {{-- Fresh VMs are the machines that used to fail: a clean Windows
                 image often lacks the VC++ runtime and a working winget, which
                 is what the installer now repairs on its own. Say so here so an
                 operator enrolling a VM knows there is nothing extra to do. --}}
            <div class="pd-card p-4 border-emerald-200 bg-emerald-50/50 flex gap-3">
                <svg class="h-5 w-5 text-emerald-600 shrink-0 mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
                <div class="text-sm text-slate-700 space-y-1">
                    <p class="font-semibold text-emerald-800">Fresh machines &amp; VMs are prepared automatically.</p>
                    <p>
                        A clean Windows image — especially a new VM — usually lacks the <strong>Visual C++ runtime</strong>
                        and a working <strong>winget</strong> for the SYSTEM account. The installer checks both and repairs
                        them before the agent starts, so a bare VM enrols with no manual prep. This is what previously
                        caused installs to fail with exit <code class="font-mono text-xs">-1073741515</code>.
                    </p>
                    <p class="text-slate-500">
                        Anything that still can't be fixed shows on the machine's page as a
                        <strong>“Not ready to deploy”</strong> banner naming the exact remedy — so a VM is never silently broken.
                    </p>
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
