<?php

namespace App\Livewire\Concerns;

use Livewire\WithPagination;

/**
 * WithPagination + the compact Previous/Next pagination view.
 */
trait WithCompactPagination
{
    use WithPagination;

    public function paginationView(): string
    {
        return 'pagination.compact';
    }

    public function paginationSimpleView(): string
    {
        return 'pagination.compact';
    }
}
