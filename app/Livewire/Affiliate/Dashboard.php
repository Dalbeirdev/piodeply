<?php

namespace App\Livewire\Affiliate;

use App\Models\Affiliate;
use App\Services\AffiliateService;
use Livewire\Component;

/**
 * An affiliate's own dashboard: their referral link, performance stats, and a
 * way to request a payout. Shown to any signed-in user linked to an affiliate
 * record; others see a short notice.
 */
class Dashboard extends Component
{
    public ?Affiliate $affiliate = null;

    public ?string $message = null;

    public function mount(): void
    {
        $this->affiliate = Affiliate::where('user_id', auth()->id())->first();
    }

    public function requestPayout(AffiliateService $affiliates): void
    {
        if ($this->affiliate === null) {
            return;
        }

        $this->message = null;
        try {
            $amount = $this->affiliate->availableBalanceCents();
            $affiliates->requestWithdrawal($this->affiliate, $amount, $this->affiliate->payout_method);
            $this->message = 'Payout requested — an admin will process it.';
        } catch (\Throwable $e) {
            $this->message = $e->getMessage();
        }
    }

    public function render(AffiliateService $affiliates)
    {
        return view('livewire.affiliate.dashboard', [
            'stats'       => $this->affiliate ? $affiliates->stats($this->affiliate) : null,
            'commissions' => $this->affiliate
                ? $this->affiliate->commissions()->latest()->limit(20)->get()
                : collect(),
            'withdrawals' => $this->affiliate
                ? $this->affiliate->withdrawals()->latest()->get()
                : collect(),
        ])->layout('layouts.app');
    }
}
