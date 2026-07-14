@props(['options' => [], 'placeholder' => 'Select…'])

{{--
    Searchable combobox bound to a Livewire property via wire:model.
    $options is a list of ['value' => ..., 'label' => ...].
--}}
<div
    x-data="{
        open: false,
        search: '',
        value: @entangle($attributes->wire('model')),
        options: {{ \Illuminate\Support\Js::from($options) }},
        get filtered() {
            const q = this.search.trim().toLowerCase();
            return q === '' ? this.options : this.options.filter(o => o.label.toLowerCase().includes(q));
        },
        get selectedLabel() {
            const found = this.options.find(o => String(o.value) === String(this.value ?? ''));
            return found ? found.label : null;
        },
        pick(option) {
            this.value = option.value;
            this.open = false;
            this.search = '';
        },
        toggle() {
            this.open = ! this.open;
            if (this.open) this.$nextTick(() => this.$refs.search.focus());
        },
    }"
    @click.outside="open = false"
    @keydown.escape.stop="open = false"
    class="relative"
>
    <button type="button" @click="toggle()"
            class="pd-select w-full text-left flex items-center justify-between gap-2"
            :aria-expanded="open" aria-haspopup="listbox">
        <span class="truncate" x-text="selectedLabel ?? @js($placeholder)"
              :class="selectedLabel ? 'text-slate-900' : 'text-slate-400'"></span>
        <svg class="h-4 w-4 text-slate-400 shrink-0" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div x-show="open" x-cloak
         class="absolute z-30 mt-1.5 w-full rounded-xl border border-slate-200 bg-white shadow-lg p-2">
        <div class="relative mb-1">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400 pointer-events-none"
                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            <input x-ref="search" x-model="search" type="text" placeholder="Type to search…"
                   aria-label="Search options"
                   class="pd-input w-full pl-8 py-1.5 text-sm"
                   @keydown.enter.prevent="if (filtered.length >= 1) pick(filtered[0])">
        </div>
        <ul class="max-h-56 overflow-y-auto" role="listbox">
            <template x-for="option in filtered" :key="option.value">
                <li role="option" :aria-selected="String(option.value) === String(value ?? '')">
                    <button type="button" @click="pick(option)"
                            class="w-full text-left px-3 py-1.5 rounded-lg text-sm text-slate-700 hover:bg-teal-50 hover:text-teal-800 transition-colors"
                            :class="String(option.value) === String(value ?? '') ? 'bg-teal-50 text-teal-800 font-semibold' : ''"
                            x-text="option.label"></button>
                </li>
            </template>
            <li x-show="filtered.length === 0" class="px-3 py-2 text-sm text-slate-400">No matches.</li>
        </ul>
    </div>
</div>
