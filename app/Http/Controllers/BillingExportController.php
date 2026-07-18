<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the payment ledger as CSV for finance / reconciliation.
 */
class BillingExportController extends Controller
{
    public function payments(): StreamedResponse
    {
        abort_unless(Gate::allows('manage-billing'), 403);

        $filename = 'payments-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Reference', 'Email', 'Plan', 'Amount', 'Currency', 'Status']);

            Payment::orderByDesc('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    fputcsv($out, [
                        $p->created_at?->toDateTimeString(),
                        $p->reference,
                        $p->customer_email,
                        $p->plan,
                        number_format(($p->amount_total ?? 0) / 100, 2),
                        strtoupper($p->currency ?? 'usd'),
                        $p->status,
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
