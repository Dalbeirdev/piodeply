@props(['name' => ''])

@php
    $icons = [
        'Web Browsers'          => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',
        'Messaging'             => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'Media'                 => '<circle cx="12" cy="12" r="9"/><path d="m10 9 5 3-5 3z"/>',
        '.NET'                  => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M9 9h6v6H9zM4 12h5M15 12h5M12 4v5M12 15v5"/>',
        'Java'                  => '<path d="M17 9h1.5a3.5 3.5 0 0 1 0 7H17"/><path d="M3 9h14v7a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4zM7 2v3M11 2v3"/>',
        'Imaging'               => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
        'Documents'             => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
        'Security'              => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'File Sharing'          => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"/>',
        'Online Storage'        => '<path d="M18 10a4 4 0 0 0-7.7-1.3A3.5 3.5 0 1 0 7 16h10.5a3.5 3.5 0 0 0 .5-6z"/>',
        'Other'                 => '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
        'Utilities'             => '<path d="M14.7 6.3a4 4 0 0 0-5.6 5L3 17.4V21h3.6l6.1-6.1a4 4 0 0 0 5-5.6l-2.5 2.5-2.1-.6-.6-2.1z"/>',
        'Compression'           => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18M9 6h3M12 9h3M9 12h3M12 15h3"/>',
        'VC++ Redistributables' => '<rect x="6" y="6" width="12" height="12" rx="1"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>',
        'Developer Tools'       => '<path d="m8 6-6 6 6 6M16 6l6 6-6 6"/>',
        'Runtimes'              => '<path d="M12 2v4M12 18v4M4 12H2M22 12h-2M6 6 4.5 4.5M18 6l1.5-1.5M6 18l-1.5 1.5M18 18l1.5 1.5"/><circle cx="12" cy="12" r="4"/>',
    ];

    $path = $icons[$name] ?? $icons['Other'];
@endphp

<svg {{ $attributes->merge(['class' => 'h-5 w-5']) }} viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
     role="img" aria-label="{{ $name }}">{!! $path !!}</svg>
