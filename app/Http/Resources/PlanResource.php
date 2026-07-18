<?php

namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'slug'            => $this->slug,
            'name'            => $this->name,
            'device_limit'    => $this->device_limit,
            'currency'        => $this->currency,
            'monthly_cents'   => $this->monthly_price_cents,
            'yearly_cents'    => $this->yearly_price_cents,
            'monthly'         => $this->monthlyPrice(),
            'yearly'          => $this->yearlyPrice(),
            'per_device'      => round($this->perDeviceCents() / 100, 2),
            'yearly_savings'  => round($this->yearlySavingsCents() / 100, 2),
            'features'        => $this->features(),
            'is_recommended'  => $this->is_recommended,
        ];
    }
}
