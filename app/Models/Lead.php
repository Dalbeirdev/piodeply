<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $fillable = [
        'type', 'name', 'email', 'company', 'fleet_size', 'message', 'ip', 'handled_at', 'read_at',
    ];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime', 'read_at' => 'datetime'];
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /** A courteous mailto reply, addressed and subject-lined for the operator. */
    public function replyMailto(): string
    {
        $subject = $this->type === 'access_request'
            ? 'Your PioDeploy access request'
            : 'Re: your message to PioDeploy';

        $body = "Hi ".\Illuminate\Support\Str::of($this->name)->explode(' ')->first().",\n\n"
            ."Thanks for getting in touch with PioDeploy.\n\n";

        return 'mailto:'.$this->email
            .'?subject='.rawurlencode($subject)
            .'&body='.rawurlencode($body);
    }
}
