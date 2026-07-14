@props(['label', 'icon', 'variant' => 'default', 'href' => null])

@php
    $variantClass = match ($variant) {
        'danger' => 'pd-icon-btn-danger',
        'amber' => 'pd-icon-btn-amber',
        default => '',
    };

    $icons = [
        'edit'       => '<path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
        'delete'     => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/>',
        'power'      => '<path d="M18.4 6.6a9 9 0 1 1-12.8 0M12 2v10"/>',
        'restore'    => '<path d="M3 12a9 9 0 1 0 2.6-6.4L3 8"/><path d="M3 3v5h5"/>',
        'retry'      => '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
        'cancel'     => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
        'key'        => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3 21 2M15 7l3 3"/>',
        'reassign'   => '<path d="m16 3 4 4-4 4M20 7H4M8 21l-4-4 4-4M4 17h16"/>',
    ];
    $path = $icons[$icon] ?? $icons['edit'];
    $classes = trim("pd-icon-btn {$variantClass}");
@endphp

@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }} aria-label="{{ $label }}" title="{{ $label }}">
        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $path !!}</svg>
        <span class="pd-tooltip" role="tooltip">{{ $label }}</span>
    </a>
@else
    <button type="button" {{ $attributes->merge(['class' => $classes]) }} aria-label="{{ $label }}" title="{{ $label }}">
        <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $path !!}</svg>
        <span class="pd-tooltip" role="tooltip">{{ $label }}</span>
    </button>
@endif
