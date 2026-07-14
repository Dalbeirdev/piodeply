<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\BillingService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billing,
    ) {
    }

    /** Start a subscription checkout from the pricing page. */
    public function checkout(Request $request)
    {
        abort_unless($this->billing->isConfigured(), 404, 'Online payment is not enabled.');

        $validated = $request->validate([
            'machines' => ['required', 'integer', 'between:1,100000'],
        ]);

        $url = $this->billing->createCheckout(
            machines: (int) $validated['machines'],
            successUrl: route('billing.success'),
            cancelUrl: route('pricing'),
        );

        abort_if($url === null, 422, 'Could not start checkout. Please try again.');

        return redirect()->away($url);
    }

    public function success()
    {
        return view('marketing.billing-success');
    }

    /**
     * Stripe webhook — verifies the signature, then records the payment.
     * Public route (Stripe calls it), but every request is HMAC-verified.
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();

        if (! $this->billing->verifyWebhook($payload, $request->header('Stripe-Signature'))) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        $event = json_decode($payload, true);
        $type = $event['type'] ?? '';

        if ($type === 'checkout.session.completed') {
            $session = $event['data']['object'] ?? [];

            Payment::updateOrCreate(
                ['reference' => $session['id'] ?? null],
                [
                    'provider'       => 'stripe',
                    'customer_email' => $session['customer_details']['email'] ?? ($session['customer_email'] ?? null),
                    'plan'           => 'per-machine',
                    'quantity'       => isset($session['metadata']['machines']) ? (int) $session['metadata']['machines'] : null,
                    'amount_total'   => $session['amount_total'] ?? null,
                    'currency'       => $session['currency'] ?? null,
                    'status'         => ($session['payment_status'] ?? '') === 'paid' ? 'paid' : 'pending',
                    'meta'           => ['mode' => $session['mode'] ?? null],
                ]
            );
        }

        return response()->json(['received' => true]);
    }
}
