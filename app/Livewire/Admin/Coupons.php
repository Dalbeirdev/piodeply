<?php

namespace App\Livewire\Admin;

use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Admin coupon management: create/edit/delete, activate, and see redemption
 * analytics. Codes are validated for customers by CouponService.
 */
class Coupons extends Component
{
    use WithPagination;

    public bool $showForm = false;

    public ?int $editingId = null;

    // Form fields
    public string $code = '';
    public string $name = '';
    public ?string $description = null;
    public string $type = 'percent';
    public ?int $value = null;
    public string $duration = 'once';
    public ?int $durationInMonths = null;
    public ?int $planId = null;
    public ?string $redeemBy = null;
    public ?int $maxRedemptions = null;
    public ?int $maxPerCustomer = null;
    public bool $autoApply = false;
    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('manage-billing');
    }

    public function rules(): array
    {
        return [
            'code'             => ['required', 'string', 'max:50', Rule::unique('coupons', 'code')->ignore($this->editingId)],
            'name'             => ['required', 'string', 'max:120'],
            'description'      => ['nullable', 'string', 'max:500'],
            'type'             => ['required', Rule::in(Coupon::TYPES)],
            'value'            => ['required', 'integer', 'min:1', $this->type === 'percent' ? 'max:100' : 'max:100000000'],
            'duration'         => ['required', Rule::in(Coupon::DURATIONS)],
            'durationInMonths' => ['nullable', 'integer', 'min:1', 'max:36'],
            'planId'           => ['nullable', 'integer', Rule::exists('plans', 'id')],
            'redeemBy'         => ['nullable', 'date'],
            'maxRedemptions'   => ['nullable', 'integer', 'min:1'],
            'maxPerCustomer'   => ['nullable', 'integer', 'min:1'],
            'autoApply'        => ['boolean'],
            'isActive'         => ['boolean'],
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $c = Coupon::findOrFail($id);
        $this->editingId = $c->id;
        $this->code = $c->code;
        $this->name = $c->name;
        $this->description = $c->description;
        $this->type = $c->type;
        $this->value = $c->value;
        $this->duration = $c->duration;
        $this->durationInMonths = $c->duration_in_months;
        $this->planId = $c->plan_id;
        $this->redeemBy = $c->redeem_by?->format('Y-m-d');
        $this->maxRedemptions = $c->max_redemptions;
        $this->maxPerCustomer = $c->max_per_customer;
        $this->autoApply = $c->auto_apply;
        $this->isActive = $c->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage-billing');
        $data = $this->validate();

        Coupon::updateOrCreate(
            ['id' => $this->editingId],
            [
                'code'               => strtoupper(trim($data['code'])),
                'name'               => $data['name'],
                'description'        => $data['description'],
                'type'               => $data['type'],
                'value'              => $data['value'],
                'duration'           => $data['duration'],
                'duration_in_months' => $data['duration'] === 'repeating' ? $data['durationInMonths'] : null,
                'plan_id'            => $data['planId'],
                'redeem_by'          => $data['redeemBy'],
                'max_redemptions'    => $data['maxRedemptions'],
                'max_per_customer'   => $data['maxPerCustomer'],
                'auto_apply'         => $data['autoApply'],
                'is_active'          => $data['isActive'],
            ]
        );

        activity('billing')->causedBy(auth()->user())->log(($this->editingId ? 'Coupon updated: ' : 'Coupon created: ') . strtoupper($data['code']));
        session()->flash('status', $this->editingId ? 'Coupon updated.' : 'Coupon created.');
        $this->resetForm();
        $this->showForm = false;
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage-billing');
        $c = Coupon::findOrFail($id);
        $c->update(['is_active' => ! $c->is_active]);
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-billing');
        Coupon::findOrFail($id)->delete();
        session()->flash('status', 'Coupon deleted.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'code', 'name', 'description', 'value', 'durationInMonths',
            'planId', 'redeemBy', 'maxRedemptions', 'maxPerCustomer']);
        $this->type = 'percent';
        $this->duration = 'once';
        $this->autoApply = false;
        $this->isActive = true;
    }

    public function render()
    {
        return view('livewire.admin.coupons', [
            'coupons' => Coupon::with('plan')->withCount('redemptions')->latest()->paginate(15),
            'plans'   => Plan::orderBy('device_limit')->get(),
            'totalRedemptions' => \App\Models\CouponRedemption::count(),
        ])->layout('layouts.app');
    }
}
