<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AgentRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // project auth happens in AuthenticateAgent middleware
    }

    public function rules(): array
    {
        return [
            'agent_uuid'    => ['required', 'uuid'],
            'agent_version' => ['nullable', 'string', 'max:20'],
            'inventory'     => ['required', 'array'],
        ] + self::inventoryRules();
    }

    /** Shared with the inventory endpoint. */
    public static function inventoryRules(): array
    {
        return [
            'inventory.hostname'         => ['required', 'string', 'max:255'],
            'inventory.serial_number'    => ['nullable', 'string', 'max:255'],
            'inventory.manufacturer'     => ['nullable', 'string', 'max:255'],
            'inventory.model'            => ['nullable', 'string', 'max:255'],
            'inventory.os_name'          => ['nullable', 'string', 'max:255'],
            'inventory.os_version'       => ['nullable', 'string', 'max:100'],
            'inventory.windows_build'    => ['nullable', 'string', 'max:50'],
            'inventory.cpu'              => ['nullable', 'string', 'max:255'],
            'inventory.ram_bytes'        => ['nullable', 'integer', 'min:0'],
            'inventory.disk_total_bytes' => ['nullable', 'integer', 'min:0'],
            'inventory.disk_free_bytes'  => ['nullable', 'integer', 'min:0'],
            'inventory.private_ip'       => ['nullable', 'ip'],
            'inventory.mac_address'      => ['nullable', 'string', 'max:17'],
            'inventory.secure_boot'      => ['nullable', 'boolean'],
            'inventory.tpm_enabled'      => ['nullable', 'boolean'],
            'inventory.tpm_version'      => ['nullable', 'string', 'max:20'],
        ];
    }
}
