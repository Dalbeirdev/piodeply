<?php

namespace App\Livewire\Team;

use App\Enums\JobAction;
use App\Models\DeploymentRequest;
use App\Services\DeploymentService;
use Livewire\Component;

/**
 * The account owner's approval queue: deployment requests filed by members
 * whose custom role requires approval. Approving queues the real job
 * through the same guarded funnel as any deploy; rejecting closes the
 * request. Both decisions are stamped and kept as the audit trail.
 */
class Approvals extends Component
{
    public function mount(): void
    {
        $this->assertOwner();
    }

    private function assertOwner(): void
    {
        abort_if(auth()->user()->tenantClientId() === null, 403, 'Approvals are for client accounts.');
        abort_unless(auth()->user()->isClientOwner(), 403, 'Only account owners decide approvals.');
    }

    public function approve(int $requestId, DeploymentService $deployments): void
    {
        $this->assertOwner();
        $request = $this->ownPending($requestId);

        try {
            // Queued AS the owner: the owner has no overlay, so the request
            // becomes a real job — still through every tenancy/package guard.
            $result = $deployments->queueIfNeeded(
                computer: $request->computer,
                package: $request->package,
                action: JobAction::from($request->action),
                createdBy: auth()->id(),
                targetVersion: $request->target_version,
            );
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $request->update([
            'status'     => 'approved',
            'decided_by' => auth()->id(),
            'decided_at' => now(),
            'job_id'     => $result->job?->id,
        ]);

        activity('team')
            ->causedBy(auth()->user())
            ->performedOn($request)
            ->withProperties(['package' => $request->package->name, 'computer' => $request->computer->hostname])
            ->log('deployment_request_approved');

        session()->flash('status', "Approved — {$result->message}");
    }

    public function reject(int $requestId): void
    {
        $this->assertOwner();
        $request = $this->ownPending($requestId);

        $request->update([
            'status'     => 'rejected',
            'decided_by' => auth()->id(),
            'decided_at' => now(),
        ]);

        activity('team')
            ->causedBy(auth()->user())
            ->performedOn($request)
            ->withProperties(['package' => $request->package->name, 'computer' => $request->computer->hostname])
            ->log('deployment_request_rejected');

        session()->flash('status', 'Request rejected.');
    }

    private function ownPending(int $requestId): DeploymentRequest
    {
        return DeploymentRequest::where('client_id', auth()->user()->tenantClientId())
            ->where('status', 'pending')
            ->findOrFail($requestId);
    }

    public function render()
    {
        $clientId = auth()->user()->tenantClientId();

        return view('livewire.team.approvals', [
            'pending' => DeploymentRequest::with(['requester', 'computer', 'package'])
                ->where('client_id', $clientId)->where('status', 'pending')
                ->orderBy('id')->get(),
            'decided' => DeploymentRequest::with(['requester', 'computer', 'package', 'decider'])
                ->where('client_id', $clientId)->where('status', '!=', 'pending')
                ->orderByDesc('decided_at')->limit(25)->get(),
        ])->layout('layouts.app');
    }
}
