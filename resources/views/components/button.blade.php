<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-2 px-4 py-2 bg-teal-700 border border-transparent rounded-lg font-semibold text-sm text-white shadow-sm hover:bg-teal-800 focus:bg-teal-800 active:bg-teal-900 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
