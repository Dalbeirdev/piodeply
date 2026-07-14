<?php

namespace App\Livewire\Reports;

use Livewire\Component;

class ReportsIndex extends Component
{
    public function render()
    {
        abort_unless(auth()->user()->can(\App\Enums\Permission::ReportsView->value), 403);

        return view('livewire.reports.reports-index')->layout('layouts.app');
    }
}
