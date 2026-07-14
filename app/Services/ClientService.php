<?php

namespace App\Services;

use App\DTOs\ClientData;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Repositories\Contracts\ClientRepositoryInterface;
use Illuminate\Support\Facades\DB;

class ClientService
{
    public function __construct(
        private readonly ClientRepositoryInterface $clients,
    ) {
    }

    public function create(ClientData $data): Client
    {
        return DB::transaction(function () use ($data) {
            /** @var Client $client */
            $client = $this->clients->create($data->toClientAttributes());
            $this->syncContacts($client, $data->contacts);

            return $client->load('contacts');
        });
    }

    public function update(Client $client, ClientData $data): Client
    {
        return DB::transaction(function () use ($client, $data) {
            $this->clients->update($client, $data->toClientAttributes());
            $this->syncContacts($client, $data->contacts);

            return $client->fresh('contacts');
        });
    }

    public function delete(Client $client): void
    {
        $this->clients->delete($client); // soft delete
    }

    public function restore(Client $client): void
    {
        $this->clients->restore($client);
    }

    /**
     * Replace the client's contacts with the given set. Exactly one primary
     * is enforced (first flagged wins; none flagged -> first row).
     */
    private function syncContacts(Client $client, array $contacts): void
    {
        $client->contacts()->delete();

        $primarySeen = false;
        foreach (array_values($contacts) as $index => $contact) {
            $isPrimary = ! $primarySeen && (($contact['is_primary'] ?? false) || $index === 0);
            if ($isPrimary) {
                $primarySeen = true;
            }

            $client->contacts()->create([
                'name'       => $contact['name'],
                'title'      => $contact['title'] ?? null,
                'email'      => $contact['email'] ?? null,
                'phone'      => $contact['phone'] ?? null,
                'is_primary' => $isPrimary,
            ]);
        }
    }

    /**
     * CSV export of the current (non-deleted) client list.
     */
    public function exportCsv(): string
    {
        $rows = [['company_name', 'email', 'phone', 'status', 'timezone', 'city', 'country', 'billing_email', 'contacts', 'created_at']];

        Client::with('contacts')->orderBy('company_name')->get()->each(function (Client $client) use (&$rows) {
            $rows[] = [
                $client->company_name,
                $client->email,
                $client->phone,
                $client->status->value,
                $client->timezone,
                $client->city,
                $client->country,
                $client->billing_email,
                $client->contacts->count(),
                $client->created_at->toDateString(),
            ];
        });

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);

        return stream_get_contents($handle);
    }

    /**
     * CSV import. Header row required; rows are upserted by email.
     * Returns [imported, updated, skipped(list of line => reason)].
     *
     * @return array{imported: int, updated: int, skipped: array<int, string>}
     */
    public function importCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        if (count($lines) < 2) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => [1 => 'File has no data rows.']];
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $required = ['company_name', 'email'];
        if (array_diff($required, $header) !== []) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => [1 => 'Header must include company_name and email.']];
        }

        $imported = 0;
        $updated = 0;
        $skipped = [];

        foreach ($lines as $i => $line) {
            $lineNo = $i + 2;
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), null));

            $email = trim((string) $row['email']);
            $company = trim((string) $row['company_name']);
            if ($company === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[$lineNo] = 'Missing company_name or invalid email.';
                continue;
            }

            $status = strtolower(trim((string) ($row['status'] ?? '')));
            $attributes = array_filter([
                'company_name'  => $company,
                'phone'         => $row['phone'] ?? null,
                'timezone'      => $row['timezone'] ?? null,
                'city'          => $row['city'] ?? null,
                'country'       => $row['country'] ?? null,
                'billing_email' => $row['billing_email'] ?? null,
                'status'        => in_array($status, ClientStatus::values(), true) ? $status : null,
            ], fn ($v) => $v !== null && $v !== '');

            $existing = $this->clients->findByEmail($email, withTrashed: true);
            if ($existing !== null) {
                $this->clients->update($existing, $attributes);
                $updated++;
            } else {
                $this->clients->create($attributes + ['email' => $email]);
                $imported++;
            }
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }
}
