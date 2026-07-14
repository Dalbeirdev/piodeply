<?php

namespace Database\Seeders;

use App\Enums\Architecture;
use App\Enums\InstallerType;
use App\Models\Package;
use App\Models\PackageCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Ninite-style starter catalogue. All seeded packages are winget-type
 * (resolved from the official winget repository at install time), so no
 * binary URLs or checksums are fabricated. Admins add MSI/EXE versions
 * with real URLs + SHA-256 for anything self-hosted. Idempotent by slug.
 */
class PackagesSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'Web Browsers' => [
                ['Google Chrome', 'Google LLC', 'Google.Chrome', 'Freeware'],
                ['Mozilla Firefox', 'Mozilla', 'Mozilla.Firefox', 'MPL-2.0'],
                ['Microsoft Edge', 'Microsoft', 'Microsoft.Edge', 'Freeware'],
            ],
            'Messaging' => [
                ['Zoom', 'Zoom', 'Zoom.Zoom', 'Freemium'],
                ['Microsoft Teams', 'Microsoft', 'Microsoft.Teams', 'Freemium'],
                ['Slack', 'Slack Technologies', 'SlackTechnologies.Slack', 'Freemium'],
            ],
            'Media' => [
                ['VLC Media Player', 'VideoLAN', 'VideoLAN.VLC', 'GPL-2.0'],
                ['Audacity', 'Audacity Team', 'Audacity.Audacity', 'GPL-3.0'],
            ],
            'Documents' => [
                ['Adobe Acrobat Reader', 'Adobe', 'Adobe.Acrobat.Reader.64-bit', 'Freeware'],
                ['LibreOffice', 'The Document Foundation', 'TheDocumentFoundation.LibreOffice', 'MPL-2.0'],
                ['Notepad++', 'Don Ho', 'Notepad++.Notepad++', 'GPL-3.0'],
            ],
            'Security' => [
                ['Malwarebytes', 'Malwarebytes', 'Malwarebytes.Malwarebytes', 'Freemium'],
                ['Bitwarden', 'Bitwarden', 'Bitwarden.Bitwarden', 'GPL-3.0'],
            ],
            'Utilities' => [
                ['7-Zip', 'Igor Pavlov', '7zip.7zip', 'LGPL-2.1'],
                ['Microsoft PowerToys', 'Microsoft', 'Microsoft.PowerToys', 'MIT'],
                ['TeamViewer', 'TeamViewer', 'TeamViewer.TeamViewer', 'Commercial'],
            ],
            'Developer Tools' => [
                ['Visual Studio Code', 'Microsoft', 'Microsoft.VisualStudioCode', 'MIT'],
                ['Git', 'Git SCM', 'Git.Git', 'GPL-2.0'],
                ['Windows Terminal', 'Microsoft', 'Microsoft.WindowsTerminal', 'MIT'],
            ],
            'Runtimes' => [
                ['.NET Desktop Runtime 8', 'Microsoft', 'Microsoft.DotNet.DesktopRuntime.8', 'MIT'],
                ['Visual C++ Redistributable', 'Microsoft', 'Microsoft.VCRedist.2015+.x64', 'Freeware'],
            ],
        ];

        $sort = 0;
        foreach ($catalog as $categoryName => $packages) {
            $category = PackageCategory::updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName, 'sort_order' => $sort++]
            );

            foreach ($packages as [$name, $vendor, $wingetId, $license]) {
                Package::withTrashed()->updateOrCreate(
                    ['slug' => Str::slug($name)],
                    [
                        'package_category_id' => $category->id,
                        'name'                => $name,
                        'vendor'              => $vendor,
                        'winget_id'           => $wingetId,
                        'license'             => $license,
                        'installer_type'      => InstallerType::Winget,
                        'architecture'        => Architecture::X64,
                        'is_active'           => true,
                    ]
                );
            }
        }
    }
}
