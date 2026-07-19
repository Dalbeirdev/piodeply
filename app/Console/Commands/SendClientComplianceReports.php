<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientComplianceReportNotification;
use App\Services\ClientComplianceReportService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Monthly compliance PDFs for opted-in clients, mailed to each client's
 * portal users. A client with the flag off — or with no portal users — is
 * skipped silently; on-demand download always remains available.
 */
class SendClientComplianceReports extends Command
{
    protected $signature = 'reports:client-compliance';

    protected $description = 'Email the monthly compliance PDF to opted-in clients';

    public function handle(ClientComplianceReportService $reports): int
    {
        $company = (string) app(SettingsService::class)->get('branding.company_name');
        $sent = 0;

        foreach (Client::where('monthly_report', true)->get() as $client) {
            $recipients = User::role(Role::Client->value)
                ->where('client_id', $client->id)
                ->get();

            if ($recipients->isEmpty()) {
                continue;
            }

            $pdf = $reports->pdfFor($client)->output();
            $filename = $reports->filenameFor($client);

            foreach ($recipients as $user) {
                $user->notify(new ClientComplianceReportNotification($client, $pdf, $filename, $company));
            }

            $sent++;
        }

        $this->info("Compliance reports sent for {$sent} client(s).");

        return self::SUCCESS;
    }
}
