<?php

namespace App\Models;

use App\Enums\PolicyAction;
use App\Enums\PolicyFrequency;
use App\Enums\PolicyMode;
use App\Enums\PolicyVersionMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a template: which app (by winget id — portable across
 * installs and catalogues) and how it should be governed.
 */
class PolicyTemplateItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'policy_template_id', 'winget_id', 'package_name',
        'action', 'mode', 'version_mode', 'frequency', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'action'       => PolicyAction::class,
            'mode'         => PolicyMode::class,
            'version_mode' => PolicyVersionMode::class,
            'frequency'    => PolicyFrequency::class,
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PolicyTemplate::class, 'policy_template_id');
    }
}
