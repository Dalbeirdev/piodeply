<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Services\SiteContentService;
use Livewire\Component;

class ManageContent extends Component
{
    /** @var array<string, string> key => value */
    public array $values = [];

    /** Content keys contain dots, which Livewire treats as nesting — bind
     *  fields under a dot-free alias and convert back on save. */
    public static function alias(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(SiteContentService $content): void
    {
        $this->authorizeManage();

        foreach (SiteContentService::defaults() as $key => $default) {
            $this->values[self::alias($key)] = $content->get($key, $default);
        }
    }

    public function save(SiteContentService $content): void
    {
        $this->authorizeManage();

        $this->validate(['values' => ['array'], 'values.*' => ['nullable', 'string', 'max:2000']]);

        foreach (SiteContentService::defaults() as $key => $default) {
            $alias = self::alias($key);
            if (array_key_exists($alias, $this->values)) {
                $content->set($key, $this->values[$alias]);
            }
        }

        activity('settings')->causedBy(auth()->user())->log('site_content_saved');
        session()->flash('status', 'Website content saved — the public site updates immediately.');
    }

    public function resetToDefaults(SiteContentService $content): void
    {
        $this->authorizeManage();

        foreach (SiteContentService::defaults() as $key => $default) {
            $content->set($key, $default);
            $this->values[self::alias($key)] = $default;
        }

        session()->flash('status', 'Content reset to the shipped defaults.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can(Permission::SettingsManage->value), 403);
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.admin.manage-content', [
            'schema' => SiteContentService::schema(),
        ])->layout('layouts.app');
    }
}
