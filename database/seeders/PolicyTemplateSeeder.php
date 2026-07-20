<?php

namespace Database\Seeders;

use App\Models\PolicyTemplate;
use Illuminate\Database\Seeder;

/**
 * The built-in starter kits. Idempotent by name: re-seeding refreshes a
 * built-in's items to the current definition (operators tune per-project
 * policies, not the built-ins) and never touches operator-made templates.
 */
class PolicyTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // [winget id, display name, actions...] — 'install' puts it on
        // machines that lack it; 'update' keeps it current forever.
        $kits = [
            'Standard workstation' => [
                'The everyday office fleet: browser, archiver, PDF reader, media player — installed everywhere and kept current weekly.',
                [
                    ['Google.Chrome',                'Google Chrome'],
                    ['7zip.7zip',                    '7-Zip'],
                    ['Adobe.Acrobat.Reader.64-bit',  'Adobe Acrobat Reader'],
                    ['VideoLAN.VLC',                 'VLC media player'],
                    ['Notepad++.Notepad++',          'Notepad++'],
                ],
            ],
            'Security baseline' => [
                'Keeps the common attack surface patched: browsers and readers always current, known bandwidth-risk software blocked.',
                [
                    ['Google.Chrome',                'Google Chrome',        ['update']],
                    ['Mozilla.Firefox',              'Mozilla Firefox',      ['update']],
                    ['Adobe.Acrobat.Reader.64-bit',  'Adobe Acrobat Reader', ['update']],
                    ['7zip.7zip',                    '7-Zip',                ['update']],
                    ['BitTorrent.uTorrent',          'uTorrent',             ['block']],
                ],
            ],
            'Developer workstation' => [
                'Git, an editor and the common runtimes — installed and kept current for engineering machines.',
                [
                    ['Git.Git',                      'Git'],
                    ['Microsoft.VisualStudioCode',   'Visual Studio Code'],
                    ['OpenJS.NodeJS.LTS',            'Node.js LTS'],
                    ['Python.Python.3.12',           'Python 3.12'],
                    ['7zip.7zip',                    '7-Zip'],
                ],
            ],
        ];

        foreach ($kits as $name => [$description, $apps]) {
            $template = PolicyTemplate::updateOrCreate(
                ['name' => $name],
                ['description' => $description, 'is_builtin' => true]
            );

            // Refresh items wholesale — built-ins are product-defined.
            $template->items()->delete();

            $sort = 0;
            foreach ($apps as $app) {
                [$wingetId, $label] = $app;
                $actions = $app[2] ?? ['install', 'update'];

                foreach ($actions as $action) {
                    $template->items()->create([
                        'winget_id'    => $wingetId,
                        'package_name' => $label,
                        'action'       => $action,
                        'mode'         => 'enforce',
                        'version_mode' => 'latest',
                        'frequency'    => 'weekly',
                        'sort_order'   => $sort++,
                    ]);
                }
            }
        }
    }
}
