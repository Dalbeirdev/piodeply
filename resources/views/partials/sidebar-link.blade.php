{{-- One sidebar entry. Shared by the ungrouped items and the collapsible
     sections so the two cannot drift apart. --}}
@php $active = request()->routeIs($item['active']); @endphp

<li>
    <a href="{{ route($item['route']) }}"
       @if ($active) aria-current="page" @endif
       class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition-colors
              {{ $active
                  ? 'bg-teal-50 text-teal-800'
                  : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900' }}">
        <svg class="h-[18px] w-[18px] shrink-0 {{ $active ? 'text-teal-700' : 'text-slate-400' }}"
             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $item['icon'] !!}</svg>
        {{ __($item['label']) }}
    </a>
</li>
