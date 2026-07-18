<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\BillingPortalService;
use Illuminate\Support\Facades\Gate;

/**
 * Streams a Stripe-hosted invoice PDF. Gated by the billing ability; a
 * non-existent (or not-ours) invoice is a 404, never someone else's document.
 */
class BillingInvoiceController extends Controller
{
    public function download(string $invoiceId, BillingPortalService $portal)
    {
        abort_unless(Gate::allows('manage-billing'), 403);

        $response = $portal->downloadInvoice(Account::current(), $invoiceId);

        abort_if($response === null, 404, 'Invoice not found.');

        return $response;
    }
}
