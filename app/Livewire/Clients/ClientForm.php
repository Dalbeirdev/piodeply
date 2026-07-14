<?php

namespace App\Livewire\Clients;

use App\DTOs\ClientData;
use App\Enums\ClientStatus;
use App\Models\Client;
use App\Services\ClientService;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class ClientForm extends Component
{
    use WithFileUploads;

    public ?Client $client = null;

    public string $company_name = '';
    public string $email = '';
    public ?string $phone = null;
    public ?string $address_line1 = null;
    public ?string $address_line2 = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $postal_code = null;
    public ?string $country = null;
    public string $timezone = 'UTC';
    public string $status = 'active';
    public ?string $billing_email = null;
    public ?string $billing_address = null;
    public ?string $billing_tax_id = null;
    public ?string $notes = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $logo = null;

    /** @var list<array{name: string, title: ?string, email: ?string, phone: ?string, is_primary: bool}> */
    public array $contacts = [];

    public function mount(?Client $client = null): void
    {
        if ($client !== null && $client->exists) {
            $this->authorize('update', $client);
            $this->client = $client;
            $this->fill($client->only([
                'company_name', 'email', 'phone', 'address_line1', 'address_line2',
                'city', 'state', 'postal_code', 'country', 'timezone',
                'billing_email', 'billing_address', 'billing_tax_id', 'notes',
            ]));
            $this->status = $client->status->value;
            $this->contacts = $client->contacts->map(fn ($contact) => [
                'name'       => $contact->name,
                'title'      => $contact->title,
                'email'      => $contact->email,
                'phone'      => $contact->phone,
                'is_primary' => $contact->is_primary,
            ])->all();
        } else {
            $this->authorize('create', Client::class);
        }
    }

    public function addContact(): void
    {
        $this->contacts[] = ['name' => '', 'title' => null, 'email' => null, 'phone' => null, 'is_primary' => false];
    }

    public function removeContact(int $index): void
    {
        unset($this->contacts[$index]);
        $this->contacts = array_values($this->contacts);
    }

    public function save(ClientService $service)
    {
        $this->authorize($this->client ? 'update' : 'create', $this->client ?? Client::class);

        $validated = $this->validate([
            'company_name'      => ['required', 'string', 'max:255'],
            'email'             => ['required', 'email', 'max:255',
                Rule::unique('clients', 'email')->ignore($this->client?->id)->withoutTrashed()],
            'phone'             => ['nullable', 'string', 'max:50'],
            'address_line1'     => ['nullable', 'string', 'max:255'],
            'address_line2'     => ['nullable', 'string', 'max:255'],
            'city'              => ['nullable', 'string', 'max:100'],
            'state'             => ['nullable', 'string', 'max:100'],
            'postal_code'       => ['nullable', 'string', 'max:20'],
            'country'           => ['nullable', 'string', 'max:100'],
            'timezone'          => ['required', 'timezone:all'],
            'status'            => ['required', Rule::in(ClientStatus::values())],
            'billing_email'     => ['nullable', 'email', 'max:255'],
            'billing_address'   => ['nullable', 'string', 'max:255'],
            'billing_tax_id'    => ['nullable', 'string', 'max:100'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'logo'              => ['nullable', 'image', 'max:1024'],
            'contacts'          => ['array', 'max:10'],
            'contacts.*.name'   => ['required', 'string', 'max:255'],
            'contacts.*.title'  => ['nullable', 'string', 'max:100'],
            'contacts.*.email'  => ['nullable', 'email', 'max:255'],
            'contacts.*.phone'  => ['nullable', 'string', 'max:50'],
        ]);

        $logoPath = $this->client?->logo_path;
        if ($this->logo !== null) {
            $logoPath = $this->logo->store('clients/logos', 'public');
        }

        $data = new ClientData(
            companyName: $validated['company_name'],
            email: $validated['email'],
            phone: $validated['phone'] ?? null,
            addressLine1: $validated['address_line1'] ?? null,
            addressLine2: $validated['address_line2'] ?? null,
            city: $validated['city'] ?? null,
            state: $validated['state'] ?? null,
            postalCode: $validated['postal_code'] ?? null,
            country: $validated['country'] ?? null,
            timezone: $validated['timezone'],
            status: ClientStatus::from($validated['status']),
            billingEmail: $validated['billing_email'] ?? null,
            billingAddress: $validated['billing_address'] ?? null,
            billingTaxId: $validated['billing_tax_id'] ?? null,
            notes: $validated['notes'] ?? null,
            logoPath: $logoPath,
            contacts: array_map(fn (array $contact) => [
                'name'       => $contact['name'],
                'title'      => $contact['title'] ?? null,
                'email'      => $contact['email'] ?? null,
                'phone'      => $contact['phone'] ?? null,
                'is_primary' => (bool) ($contact['is_primary'] ?? false),
            ], $this->contacts),
        );

        $this->client
            ? $service->update($this->client, $data)
            : $service->create($data);

        session()->flash('status', 'Client saved.');

        return $this->redirectRoute('clients.index');
    }

    public function render()
    {
        return view('livewire.clients.client-form', [
            'statuses'  => ClientStatus::cases(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ])->layout('layouts.app');
    }
}
