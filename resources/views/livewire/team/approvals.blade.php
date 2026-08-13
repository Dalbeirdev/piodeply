<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-slate-900 leading-tight">Approvals</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            @if (session('status'))
                <div class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-700" role="status">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700" role="alert">{{ session('error') }}</div>
            @endif

            <div class="rounded-md bg-slate-50 border border-slate-200 p-3 text-sm text-slate-600">
                Deployment requests from team members whose role
                <b>requires approval</b>. Approving queues the job immediately;
                rejecting closes the request. Both are kept below as history.
            </div>

            <div class="pd-card overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs text-slate-400 uppercase tracking-wide">
                            <th class="px-6 py-3">Requested by</th>
                            <th class="px-6 py-3">Action</th>
                            <th class="px-6 py-3">Software</th>
                            <th class="px-6 py-3">Machine</th>
                            <th class="px-6 py-3">When</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($pending as $request)
                            <tr>
                                <td class="px-6 py-3 font-medium text-slate-800">{{ $request->requester?->name ?? '—' }}</td>
                                <td class="px-6 py-3 text-slate-600 capitalize">{{ $request->action }}</td>
                                <td class="px-6 py-3 text-slate-600">{{ $request->package?->name }}
                                    @if ($request->target_version) <span class="text-xs text-slate-400">v{{ $request->target_version }}</span> @endif
                                </td>
                                <td class="px-6 py-3 text-slate-600">{{ $request->computer?->hostname }}</td>
                                <td class="px-6 py-3 text-slate-500">{{ $request->created_at->diffForHumans() }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap space-x-2">
                                    <button type="button" wire:click="approve({{ $request->id }})"
                                            class="inline-flex items-center px-3 py-1.5 bg-teal-700 rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-teal-800">
                                        Approve
                                    </button>
                                    <button type="button" wire:click="reject({{ $request->id }})"
                                            wire:confirm="Reject this request? {{ $request->requester?->name }} will not be able to run it."
                                            class="text-sm text-rose-600 hover:text-rose-700 font-medium">
                                        Reject
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-slate-500">
                                Nothing waiting — requests appear here the moment a team member whose
                                role requires approval tries to deploy.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($decided->isNotEmpty())
                <div class="pd-card overflow-x-auto">
                    <p class="px-6 pt-4 text-xs font-semibold text-slate-400 uppercase tracking-wide">Recent decisions</p>
                    <table class="min-w-full divide-y divide-slate-100 text-sm">
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($decided as $request)
                                <tr class="text-slate-500">
                                    <td class="px-6 py-2.5">
                                        <span class="pd-badge {{ $request->status === 'approved' ? 'pd-badge-slate' : '' }} {{ $request->status === 'rejected' ? 'bg-rose-50 text-rose-700 border-rose-200' : '' }}">
                                            {{ ucfirst($request->status) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-2.5">{{ $request->requester?->name }} · {{ $request->action }} {{ $request->package?->name }} on {{ $request->computer?->hostname }}</td>
                                    <td class="px-6 py-2.5 text-right">{{ $request->decided_at?->diffForHumans() }} by {{ $request->decider?->name }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
