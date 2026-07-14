<?php

namespace App\DTOs;

use App\Enums\ClientStatus;

class ClientData extends DataTransferObject
{
    /**
     * @param list<array{name: string, title: ?string, email: ?string, phone: ?string, is_primary: bool}> $contacts
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $email,
        public readonly ?string $phone = null,
        public readonly ?string $addressLine1 = null,
        public readonly ?string $addressLine2 = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $country = null,
        public readonly string $timezone = 'UTC',
        public readonly ClientStatus $status = ClientStatus::Active,
        public readonly ?string $billingEmail = null,
        public readonly ?string $billingAddress = null,
        public readonly ?string $billingTaxId = null,
        public readonly ?string $notes = null,
        public readonly ?string $logoPath = null,
        public readonly array $contacts = [],
    ) {
    }

    public function toClientAttributes(): array
    {
        return [
            'company_name'    => $this->companyName,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'address_line1'   => $this->addressLine1,
            'address_line2'   => $this->addressLine2,
            'city'            => $this->city,
            'state'           => $this->state,
            'postal_code'     => $this->postalCode,
            'country'         => $this->country,
            'timezone'        => $this->timezone,
            'status'          => $this->status,
            'billing_email'   => $this->billingEmail,
            'billing_address' => $this->billingAddress,
            'billing_tax_id'  => $this->billingTaxId,
            'notes'           => $this->notes,
            'logo_path'       => $this->logoPath,
        ];
    }
}
