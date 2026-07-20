<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Livewire\Concerns\WithCompactPagination;
use App\Models\Signup;
use App\Services\SignupApprovalService;
use Livewire\Component;

/**
 * The approval queue for self-service signups. The admin's one question is
 * "did the money actually arrive?" — so payment status leads every row,
 * and approval is a single click once it says paid (or the operator has
 * verified an out-of-band payment themselves).
 */
class SignupsIndex extends Component
{
    use WithCompactPagination;

    public string $search = '';

    /** Open applications first — decided ones are history. */
    public bool $openOnly = true;

    public ?int $rejectingId = null;

    public string $rejectionReason = '';

    public function updating($name, $value): void
    {
        if (in_array($name, ['search', 'openOnly'], true)) {
            $this->resetPage();
        }
    }

    public function approve(int $signupId, SignupApprovalService $approvals): void
    {
        abort_unless(auth()->user()->can(Permission::UsersCreate->value), 403);

        $signup = Signup::findOrFail($signupId);

        try {
            $approvals->approve($signup, auth()->user());
            session()->flash('status', "{$signup->company_name} approved — welcome email sent to {$signup->email}.");
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function startReject(int $signupId): void
    {
        abort_unless(auth()->user()->can(Permission::UsersCreate->value), 403);

        $this->rejectingId = $signupId;
        $this->rejectionReason = '';
    }

    public function confirmReject(SignupApprovalService $approvals): void
    {
        abort_unless(auth()->user()->can(Permission::UsersCreate->value), 403);

        $signup = Signup::findOrFail($this->rejectingId);

        try {
            $approvals->reject($signup, auth()->user(), $this->rejectionReason);
            session()->flash('status', "Signup from {$signup->company_name} rejected.");
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->rejectingId = null;
        $this->rejectionReason = '';
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectionReason = '';
    }

    public function render()
    {
        abort_unless(auth()->user()->can(Permission::UsersCreate->value), 403);

        return view('livewire.admin.signups-index', [
            'signups' => Signup::query()
                ->when($this->openOnly, fn ($q) => $q->whereNotIn('status', [Signup::STATUS_APPROVED, Signup::STATUS_REJECTED]))
                ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('company_name', 'like', "%{$this->search}%")
                    ->orWhere('contact_name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")))
                ->latest()
                ->paginate(20),
            'openCount' => Signup::whereNotIn('status', [Signup::STATUS_APPROVED, Signup::STATUS_REJECTED])->count(),
        ])->layout('layouts.app');
    }
}
