<?php

namespace App\Http\Controllers;

use App\Models\AffiliateCommission;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams every affiliate commission as CSV for the admin's records.
 */
class AffiliateExportController extends Controller
{
    public function commissions(): StreamedResponse
    {
        abort_unless(Gate::allows('manage-billing'), 403);

        $filename = 'affiliate-commissions-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Affiliate', 'Code', 'Account', 'Base', 'Commission', 'Status', 'Created', 'Paid']);

            AffiliateCommission::with('affiliate')->orderBy('id')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    fputcsv($out, [
                        $c->affiliate?->name,
                        $c->affiliate?->code,
                        $c->account_id,
                        number_format($c->base_amount_cents / 100, 2),
                        number_format($c->amount_cents / 100, 2),
                        $c->status,
                        $c->created_at?->toDateTimeString(),
                        $c->paid_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
