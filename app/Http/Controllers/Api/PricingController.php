<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public read-only pricing API. Backs the marketing calculator and any
 * external site that wants to show live plans. No auth — it exposes only the
 * catalogue and derived quotes, never customer data — but it is rate limited.
 */
class PricingController extends Controller
{
    public function __construct(private readonly PricingService $pricing)
    {
    }

    /** GET /api/v1/billing/plans */
    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => PlanResource::collection($this->pricing->plans()),
        ]);
    }

    /**
     * POST /api/v1/billing/pricing/calculate
     * Body: { "devices": <int 1..1000000> }
     */
    public function calculate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'devices' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $quote = $this->pricing->calculate($validated['devices']);

        // Never leak the Eloquent model through the API surface.
        $quote['plan'] = $quote['plan'] ? new PlanResource($quote['plan']) : null;

        return response()->json(['data' => $quote]);
    }
}
