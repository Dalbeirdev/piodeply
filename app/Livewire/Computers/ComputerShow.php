<?php

namespace App\Livewire\Computers;

use App\Models\Computer;
use Livewire\Component;

class ComputerShow extends Component
{
    public Computer $computer;

    public function mount(Computer $computer): void
    {
        $this->authorize('view', $computer);
        $this->computer = $computer->load('project.client');
    }

    public function render()
    {
        return view('livewire.computers.computer-show')->layout('layouts.app');
    }
}
