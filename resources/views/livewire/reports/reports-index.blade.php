<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-800 leading-tight">{{ __('Reports') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="{{ route('reports.compliance') }}" class="pd-card p-6 hover:ring-2 hover:ring-teal-500 transition">
                    <div class="flex items-center gap-3 mb-2">
                        <svg class="h-6 w-6 text-teal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-3.6 8-9V5l-8-3-8 3v8c0 5.4 8 9 8 9z"/><path d="m9 12 2 2 4-4"/>
                        </svg>
                        <h3 class="font-semibold text-slate-800">Policy compliance</h3>
                    </div>
                    <p class="text-sm text-slate-500">
                        Every active policy with its compliance percentage and drift buckets —
                        the customer-facing "are we in desired state" report.
                    </p>
                </a>

                <a href="{{ route('reports.deployments') }}" class="pd-card p-6 hover:ring-2 hover:ring-teal-500 transition">
                    <div class="flex items-center gap-3 mb-2">
                        <svg class="h-6 w-6 text-teal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>
                        </svg>
                        <h3 class="font-semibold text-slate-800">Deployment activity</h3>
                    </div>
                    <p class="text-sm text-slate-500">
                        Jobs over a date range with success rate, failures and full execution
                        detail — what actually happened on the fleet.
                    </p>
                </a>

                <a href="{{ route('reports.computers') }}" class="pd-card p-6 hover:ring-2 hover:ring-teal-500 transition">
                    <div class="flex items-center gap-3 mb-2">
                        <svg class="h-6 w-6 text-teal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>
                        </svg>
                        <h3 class="font-semibold text-slate-800">Fleet health</h3>
                    </div>
                    <p class="text-sm text-slate-500">
                        Every machine with ring, agent state, last check-in and disk pressure —
                        spot offline agents and machines that need attention.
                    </p>
                </a>
            </div>

            <p class="mt-4 text-sm text-slate-500">
                All reports respect your data scope and export to CSV
                @cannot(\App\Enums\Permission::ReportsExport->value) (export requires the “Export reports” permission) @endcannot.
            </p>
        </div>
    </div>
</div>
