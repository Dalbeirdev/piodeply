<?php

namespace App\Livewire\Admin;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Services\AffiliateService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Admin side of the referral programme: create/approve affiliates, approve or
 * reject accrued commissions, and mark payouts paid.
 */
class Affiliates extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';
    public string $email = '';
    public string $code = '';
    public string $commissionType = 'percentage';
    public ?int $commissionRate = 20;
    public bool $recurring = true;
    public string $status = 'approved';

    /** Affiliate whose commissions/withdrawals are shown. */
    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->authorize('manage-billing');
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:120'],
            'email'          => ['required', 'email', 'max:190'],
            'code'           => ['required', 'string', 'alpha_dash', 'max:40', Rule::unique('affiliates', 'code')->ignore($this->editingId)],
            'commissionType' => ['required', Rule::in(Affiliate::TYPES)],
            'commissionRate' => ['required', 'integer', 'min:1', $this->commissionType === 'percentage' ? 'max:100' : 'max:10000000'],
            'recurring'      => ['boolean'],
            'status'         => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'email', 'code']);
        $this->commissionType = 'percentage';
        $this->commissionRate = 20;
        $this->recurring = true;
        $this->status = 'approved';
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $a = Affiliate::findOrFail($id);
        $this->editingId = $a->id;
        $this->name = $a->name;
        $this->email = $a->email;
        $this->code = $a->code;
        $this->commissionType = $a->commission_type;
        $this->commissionRate = $a->commission_rate;
        $this->recurring = $a->recurring;
        $this->status = $a->status;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage-billing');
        $data = $this->validate();

        Affiliate::updateOrCreate(['id' => $this->editingId], [
            'name'            => $data['name'],
            'email'           => $data['email'],
            'code'            => strtolower($data['code']),
            'commission_type' => $data['commissionType'],
            'commission_rate' => $data['commissionRate'],
            'recurring'       => $data['recurring'],
            'status'          => $data['status'],
        ]);

        session()->flash('status', $this->editingId ? 'Affiliate updated.' : 'Affiliate created.');
        $this->showForm = false;
    }

    public function setStatus(int $id, string $status): void
    {
        $this->authorize('manage-billing');
        abort_unless(in_array($status, ['pending', 'approved', 'rejected'], true), 400);
        Affiliate::findOrFail($id)->update(['status' => $status]);
    }

    public function select(int $id): void
    {
        $this->selectedId = $this->selectedId === $id ? null : $id;
    }

    public function approveCommission(int $id, AffiliateService $affiliates): void
    {
        $this->authorize('manage-billing');
        $affiliates->approve(AffiliateCommission::findOrFail($id));
    }

    public function rejectCommission(int $id, AffiliateService $affiliates): void
    {
        $this->authorize('manage-billing');
        $affiliates->reject(AffiliateCommission::findOrFail($id));
    }

    /** Pay out the affiliate's whole available balance and settle its commissions. */
    public function payout(int $affiliateId, AffiliateService $affiliates): void
    {
        $this->authorize('manage-billing');
        $affiliate = Affiliate::findOrFail($affiliateId);
        $amount = $affiliate->availableBalanceCents();

        if ($amount < 1) {
            session()->flash('status', 'Nothing to pay out.');

            return;
        }

        $withdrawal = $affiliates->requestWithdrawal($affiliate, $amount, $affiliate->payout_method);
        $affiliates->payWithdrawal($withdrawal, 'admin-payout');
        session()->flash('status', 'Payout recorded.');
    }

    public function render(AffiliateService $affiliates)
    {
        $selected = $this->selectedId ? Affiliate::find($this->selectedId) : null;

        return view('livewire.admin.affiliates', [
            'affiliates' => Affiliate::withCount('clicks')->latest()->get(),
            'statsFor'   => fn (Affiliate $a) => $affiliates->stats($a),
            'selected'   => $selected,
            'commissions' => $selected
                ? AffiliateCommission::where('affiliate_id', $selected->id)->latest()->limit(50)->get()
                : collect(),
            'withdrawals' => $selected
                ? AffiliateWithdrawal::where('affiliate_id', $selected->id)->latest()->get()
                : collect(),
        ])->layout('layouts.app');
    }
}
