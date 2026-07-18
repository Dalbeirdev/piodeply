<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\BillingService;
use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Stripe webhook endpoint. Verifies the signature, records every event for
 * idempotency + audit, then hands the payload to WebhookService. A redelivered
 * event (same Stripe id) that was already processed is a no-op 200; a handler
 * error is logged and returned as 500 so Stripe retries.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, BillingService $billing, WebhookService $webhooks): Response
    {
        $payload = $request->getContent();

        if (! $billing->verifyWebhook($payload, $request->header('Stripe-Signature'))) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id'])) {
            return response('Malformed payload', 400);
        }

        $record = WebhookEvent::firstOrNew(['stripe_id' => $event['id']]);

        // Idempotency: Stripe redelivers; a processed event is acknowledged, not re-run.
        if ($record->exists && $record->isProcessed()) {
            return response('Already processed', 200);
        }

        $record->fill([
            'type'    => $event['type'] ?? 'unknown',
            'payload' => $event,
            'status'  => 'received',
        ])->save();

        try {
            $outcome = $webhooks->handle($event);   // 'processed' | 'skipped'
            $record->forceFill([
                'status'       => $outcome,
                'processed_at' => now(),
                'error'        => null,
                'attempts'     => $record->attempts + 1,
            ])->save();
        } catch (\Throwable $e) {
            $record->forceFill([
                'status'   => 'failed',
                'error'    => $e->getMessage(),
                'attempts' => $record->attempts + 1,
            ])->save();
            report($e);

            return response('Handler error', 500); // Stripe will retry
        }

        return response('ok', 200);
    }
}
