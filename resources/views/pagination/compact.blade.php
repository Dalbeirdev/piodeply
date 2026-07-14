@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex items-center justify-between">
        <p class="text-sm text-gray-600">
            Page <span class="font-semibold text-gray-900">{{ $paginator->currentPage() }}</span>
            of <span class="font-semibold text-gray-900">{{ $paginator->lastPage() }}</span>
            <span class="text-gray-400">·</span>
            {{ number_format($paginator->total()) }} {{ Str::plural('result', $paginator->total()) }}
        </p>

        <div class="flex items-center gap-2">
            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center px-4 py-2 bg-gray-100 border border-gray-200 rounded-md text-sm font-medium text-gray-400 cursor-not-allowed">
                    &larr; Previous
                </span>
            @else
                <button type="button" wire:click="previousPage" wire:loading.attr="disabled" rel="prev"
                        class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                    &larr; Previous
                </button>
            @endif

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage" wire:loading.attr="disabled" rel="next"
                        class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Next &rarr;
                </button>
            @else
                <span class="inline-flex items-center px-4 py-2 bg-gray-100 border border-gray-200 rounded-md text-sm font-medium text-gray-400 cursor-not-allowed">
                    Next &rarr;
                </span>
            @endif
        </div>
    </nav>
@endif
