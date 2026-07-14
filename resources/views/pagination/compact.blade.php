@if ($paginator->hasPages())
    @php
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();

        // Windowed pages: 1 … around-current … last (mockup style: 1 2 3 … 7)
        $pages = collect([1, $last, $current - 1, $current, $current + 1])
            ->when($current <= 3, fn ($c) => $c->push(2, 3))
            ->when($current >= $last - 2, fn ($c) => $c->push($last - 1, $last - 2))
            ->filter(fn ($p) => $p >= 1 && $p <= $last)
            ->unique()->sort()->values();
    @endphp

    <nav role="navigation" aria-label="Pagination" class="flex justify-center">
        <div class="inline-flex items-stretch rounded-lg border border-gray-300 bg-white shadow-sm overflow-hidden text-sm font-medium">
            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span class="px-4 py-2 text-gray-300 select-none" aria-disabled="true">&lsaquo; Previous</span>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="prev"
                        class="px-4 py-2 text-gray-700 hover:bg-gray-50">&lsaquo; Previous</button>
            @endif

            {{-- Page numbers with ellipsis --}}
            @foreach ($pages as $index => $page)
                @if ($index > 0 && $page - $pages[$index - 1] > 1)
                    <span class="px-3 py-2 border-l border-gray-200 text-gray-400 select-none">&hellip;</span>
                @endif

                @if ($page === $current)
                    <span aria-current="page"
                          class="px-4 py-2 border-l border-gray-200 text-gray-900 font-semibold ring-1 ring-inset ring-gray-900 rounded-sm">
                        {{ $page }}
                    </span>
                @else
                    <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled"
                            aria-label="Go to page {{ $page }}"
                            class="px-4 py-2 border-l border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-gray-900">
                        {{ $page }}
                    </button>
                @endif
            @endforeach

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" rel="next"
                        class="px-4 py-2 border-l border-gray-200 text-gray-700 hover:bg-gray-50">Next &rsaquo;</button>
            @else
                <span class="px-4 py-2 border-l border-gray-200 text-gray-300 select-none" aria-disabled="true">Next &rsaquo;</span>
            @endif
        </div>
    </nav>
@endif
