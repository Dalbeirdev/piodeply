<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Client;
use App\Services\ClientComplianceReportService;

/**
 * On-demand download of a client's branded compliance PDF. Staff need the
 * reports permission; a client-portal user may fetch their own client's
 * report and nobody else's.
 */
class ClientComplianceReportController extends Controller
{
    public function download(Client $client, ClientComplianceReportService $reports)
    {
        $user = auth()->user();
        $tenantId = $user->tenantClientId();

        $allowed = $tenantId !== null
            ? $tenantId === $client->id
            : $user->can(Permission::ReportsView->value);

        abort_unless($allowed, 403);

        return $reports->pdfFor($client)->download($reports->filenameFor($client));
    }
}
