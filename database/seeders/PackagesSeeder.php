<?php

namespace Database\Seeders;

use App\Enums\Architecture;
use App\Enums\InstallerType;
use App\Models\Package;
use App\Models\PackageCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Full Ninite-style catalogue. All seeded packages are winget-type
 * (resolved from the official winget repository at install time), so no
 * binary URLs or checksums are fabricated.
 *
 * Entries are [name, vendor, winget_id, license, active]. Packages whose
 * winget IDs could not be verified are seeded INACTIVE with a note — an
 * admin confirms the ID (winget search) and activates them.
 *
 * Idempotent by slug; is_active is only set on first creation so admin
 * activation choices survive re-seeding.
 */
class PackagesSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'Web Browsers' => [
                ['Google Chrome', 'Google LLC', 'Google.Chrome', 'Freeware', true],
                ['Opera', 'Opera', 'Opera.Opera', 'Freeware', true],
                ['Mozilla Firefox', 'Mozilla', 'Mozilla.Firefox', 'MPL-2.0', true],
                ['Microsoft Edge', 'Microsoft', 'Microsoft.Edge', 'Freeware', true],
                ['Brave', 'Brave Software', 'Brave.Brave', 'MPL-2.0', true],
                ['Vivaldi', 'Vivaldi Technologies', 'Vivaldi.Vivaldi', 'Freeware', true],
            ],
            'Messaging' => [
                ['Zoom', 'Zoom', 'Zoom.Zoom', 'Freemium', true],
                ['Discord', 'Discord Inc.', 'Discord.Discord', 'Freeware', true],
                ['Microsoft Teams', 'Microsoft', 'Microsoft.Teams', 'Freemium', true],
                ['Slack', 'Slack Technologies', 'SlackTechnologies.Slack', 'Freemium', true],
                ['Pidgin', 'Pidgin', 'Pidgin.Pidgin', 'GPL-2.0', true],
                ['Mozilla Thunderbird', 'Mozilla', 'Mozilla.Thunderbird', 'MPL-2.0', true],
                ['Trillian', 'Cerulean Studios', 'CeruleanStudios.Trillian', 'Freemium', false],
            ],
            'Media' => [
                ['iTunes', 'Apple', 'Apple.iTunes', 'Freeware', true],
                ['VLC Media Player', 'VideoLAN', 'VideoLAN.VLC', 'GPL-2.0', true],
                ['AIMP', 'AIMP DevTeam', 'AIMP.AIMP', 'Freeware', true],
                ['foobar2000', 'Peter Pawlowski', 'PeterPawlowski.foobar2000', 'Freeware', true],
                ['Winamp', 'Winamp SA', 'Winamp.Winamp', 'Freeware', false],
                ['MusicBee', 'Steven Mayall', 'MusicBee.MusicBee', 'Freeware', false],
                ['Audacity', 'Audacity Team', 'Audacity.Audacity', 'GPL-3.0', true],
                ['K-Lite Codec Pack', 'Codec Guide', 'CodecGuide.K-LiteCodecPack.Standard', 'Freeware', true],
                ['GOM Player', 'GOM Lab', 'GOMLab.GOMPlayer', 'Adware', false],
                ['Spotify', 'Spotify', 'Spotify.Spotify', 'Freemium', true],
                ['MediaMonkey', 'Ventis Media', 'VentisMedia.MediaMonkey', 'Freemium', false],
                ['HandBrake', 'HandBrake Team', 'HandBrake.HandBrake', 'GPL-2.0', true],
            ],
            '.NET' => [
                ['.NET Desktop Runtime 8', 'Microsoft', 'Microsoft.DotNet.DesktopRuntime.8', 'MIT', true],
                ['.NET Desktop Runtime 9', 'Microsoft', 'Microsoft.DotNet.DesktopRuntime.9', 'MIT', true],
                ['.NET Desktop Runtime 10', 'Microsoft', 'Microsoft.DotNet.DesktopRuntime.10', 'MIT', true],
                ['ASP.NET Core Runtime 8', 'Microsoft', 'Microsoft.DotNet.AspNetCore.8', 'MIT', true],
                ['ASP.NET Core Runtime 9', 'Microsoft', 'Microsoft.DotNet.AspNetCore.9', 'MIT', true],
                ['ASP.NET Core Runtime 10', 'Microsoft', 'Microsoft.DotNet.AspNetCore.10', 'MIT', true],
                ['.NET Runtime 8', 'Microsoft', 'Microsoft.DotNet.Runtime.8', 'MIT', true],
                ['.NET Runtime 9', 'Microsoft', 'Microsoft.DotNet.Runtime.9', 'MIT', true],
                ['.NET Runtime 10', 'Microsoft', 'Microsoft.DotNet.Runtime.10', 'MIT', true],
            ],
            'Java' => [
                ['Temurin JRE 8', 'Eclipse Adoptium', 'EclipseAdoptium.Temurin.8.JRE', 'GPL-2.0-CE', true],
                ['Temurin JRE 11', 'Eclipse Adoptium', 'EclipseAdoptium.Temurin.11.JRE', 'GPL-2.0-CE', true],
                ['Temurin JRE 17', 'Eclipse Adoptium', 'EclipseAdoptium.Temurin.17.JRE', 'GPL-2.0-CE', true],
                ['Temurin JRE 21', 'Eclipse Adoptium', 'EclipseAdoptium.Temurin.21.JRE', 'GPL-2.0-CE', true],
                ['Temurin JDK 8', 'Eclipse Adoptium', 'EclipseAdoptium.Temurin.8.JDK', 'GPL-2.0-CE', true],
                ['Temurin JDK 11', 'Eclipse Adoptium', 'EclipseAdoptium.Temurin.11.JDK', 'GPL-2.0-CE', true],
                ['Temurin JDK 17', 'Eclipse Adoptium', 'EclipseAdoptium.Temurin.17.JDK', 'GPL-2.0-CE', true],
                ['Temurin JDK 21', 'Eclipse Adoptium', 'EclipseAdoptium.Temurin.21.JDK', 'GPL-2.0-CE', true],
                ['Amazon Corretto JDK 8', 'Amazon', 'Amazon.Corretto.8.JDK', 'GPL-2.0-CE', false],
                ['Amazon Corretto JDK 11', 'Amazon', 'Amazon.Corretto.11.JDK', 'GPL-2.0-CE', false],
                ['Amazon Corretto JDK 17', 'Amazon', 'Amazon.Corretto.17.JDK', 'GPL-2.0-CE', false],
                ['Amazon Corretto JDK 21', 'Amazon', 'Amazon.Corretto.21.JDK', 'GPL-2.0-CE', false],
            ],
            'Imaging' => [
                ['Krita', 'Krita Foundation', 'KDE.Krita', 'GPL-3.0', true],
                ['Blender', 'Blender Foundation', 'BlenderFoundation.Blender', 'GPL-3.0', true],
                ['Paint.NET', 'dotPDN', 'dotPDN.PaintDotNet', 'Freeware', true],
                ['GIMP', 'GIMP Team', 'GIMP.GIMP', 'GPL-3.0', true],
                ['IrfanView', 'Irfan Skiljan', 'IrfanSkiljan.IrfanView', 'Freeware', true],
                ['XnView MP', 'XnSoft', 'XnSoft.XnViewMP', 'Freeware', true],
                ['Inkscape', 'Inkscape Project', 'Inkscape.Inkscape', 'GPL-3.0', true],
                ['FastStone Image Viewer', 'FastStone Soft', 'FastStone.Viewer', 'Freemium', false],
                ['Greenshot', 'Greenshot Team', 'Greenshot.Greenshot', 'GPL-3.0', true],
                ['ShareX', 'ShareX Team', 'ShareX.ShareX', 'GPL-3.0', true],
            ],
            'Documents' => [
                ['Foxit PDF Reader', 'Foxit', 'Foxit.FoxitReader', 'Freeware', true],
                ['LibreOffice', 'The Document Foundation', 'TheDocumentFoundation.LibreOffice', 'MPL-2.0', true],
                ['SumatraPDF', 'Krzysztof Kowalczyk', 'SumatraPDF.SumatraPDF', 'GPL-3.0', true],
                ['Apache OpenOffice', 'Apache', 'Apache.OpenOffice', 'Apache-2.0', true],
                ['Adobe Acrobat Reader', 'Adobe', 'Adobe.Acrobat.Reader.64-bit', 'Freeware', true],
                ['Notepad++', 'Don Ho', 'Notepad++.Notepad++', 'GPL-3.0', true],
            ],
            'Security' => [
                ['Malwarebytes', 'Malwarebytes', 'Malwarebytes.Malwarebytes', 'Freemium', true],
                ['Avast Free Antivirus', 'Avast', 'Avast.AvastFreeAntivirus', 'Freeware', false],
                ['AVG AntiVirus Free', 'AVG', 'AVG.AntiVirusFree', 'Freeware', false],
                ['Spybot Search & Destroy', 'Safer-Networking', 'SaferNetworking.SpybotSearchAndDestroy', 'Freemium', false],
                ['Avira Free Security', 'Avira', 'Avira.Avira', 'Freeware', false],
                ['SUPERAntiSpyware', 'SUPERAntiSpyware', 'SUPERAntiSpyware.SUPERAntiSpyware', 'Freemium', false],
                ['Bitwarden', 'Bitwarden', 'Bitwarden.Bitwarden', 'GPL-3.0', true],
            ],
            'File Sharing' => [
                ['qBittorrent', 'qBittorrent Project', 'qBittorrent.qBittorrent', 'GPL-2.0', true],
            ],
            'Online Storage' => [
                ['Dropbox', 'Dropbox', 'Dropbox.Dropbox', 'Freemium', true],
                ['Google Drive for Desktop', 'Google LLC', 'Google.GoogleDrive', 'Freeware', true],
                ['Microsoft OneDrive', 'Microsoft', 'Microsoft.OneDrive', 'Freeware', true],
                ['SugarSync', 'SugarSync', 'SugarSync.SugarSync', 'Commercial', false],
            ],
            'Other' => [
                ['Evernote', 'Evernote', 'evernote.evernote', 'Freemium', true],
                ['Google Earth Pro', 'Google LLC', 'Google.EarthPro', 'Freeware', true],
                ['Steam', 'Valve', 'Valve.Steam', 'Freeware', true],
                ['Epic Games Launcher', 'Epic Games', 'EpicGames.EpicGamesLauncher', 'Freeware', true],
                ['KeePass 2', 'Dominik Reichl', 'DominikReichl.KeePass', 'GPL-2.0', true],
                ['Everything', 'voidtools', 'voidtools.Everything', 'Freeware', true],
                ['NVDA', 'NV Access', 'NVAccess.NVDA', 'GPL-2.0', true],
            ],
            'Utilities' => [
                ['AnyDesk', 'AnyDesk Software', 'AnyDeskSoftwareGmbH.AnyDesk', 'Freemium', true],
                ['TeamViewer', 'TeamViewer', 'TeamViewer.TeamViewer', 'Freemium', true],
                ['ImgBurn', 'LIGHTNING UK!', 'LIGHTNINGUK.ImgBurn', 'Freeware', false],
                ['RealVNC Server', 'RealVNC', 'RealVNC.VNCServer', 'Freemium', true],
                ['RealVNC Viewer', 'RealVNC', 'RealVNC.VNCViewer', 'Freeware', true],
                ['TightVNC', 'GlavSoft', 'GlavSoft.TightVNC', 'GPL-2.0', true],
                ['TeraCopy', 'Code Sector', 'CodeSector.TeraCopy', 'Freemium', true],
                ['CDBurnerXP', 'Canneverbe Limited', 'CDBurnerXP.CDBurnerXP', 'Freeware', false],
                ['Revo Uninstaller', 'VS Revo Group', 'RevoUninstaller.RevoUninstaller', 'Freemium', true],
                ['Launchy', 'Josh Karlin', 'Launchy.Launchy', 'GPL-2.0', false],
                ['WinDirStat', 'WinDirStat Team', 'WinDirStat.WinDirStat', 'GPL-2.0', true],
                ['WizTree', 'Antibody Software', 'AntibodySoftware.WizTree', 'Freemium', true],
                ['Glary Utilities', 'Glarysoft', 'Glarysoft.GlaryUtilities', 'Freemium', false],
                ['Open-Shell', 'Open-Shell Team', 'Open-Shell.Open-Shell-Menu', 'MIT', true],
                ['CCleaner', 'Piriform', 'Piriform.CCleaner', 'Freemium', true],
                ['Microsoft PowerToys', 'Microsoft', 'Microsoft.PowerToys', 'MIT', true],
            ],
            'Compression' => [
                ['7-Zip', 'Igor Pavlov', '7zip.7zip', 'LGPL-2.1', true],
                ['PeaZip', 'Giorgio Tani', 'Giorgiotani.Peazip', 'LGPL-3.0', true],
                ['WinRAR', 'win.rar GmbH', 'RARLab.WinRAR', 'Trialware', true],
            ],
            'VC++ Redistributables' => [
                ['VC++ Redist 2015+ x64', 'Microsoft', 'Microsoft.VCRedist.2015+.x64', 'Freeware', true],
                ['VC++ Redist 2015+ x86', 'Microsoft', 'Microsoft.VCRedist.2015+.x86', 'Freeware', true],
                ['VC++ Redist 2015+ arm64', 'Microsoft', 'Microsoft.VCRedist.2015+.arm64', 'Freeware', true],
                ['VC++ Redist 2013 x64', 'Microsoft', 'Microsoft.VCRedist.2013.x64', 'Freeware', true],
                ['VC++ Redist 2013 x86', 'Microsoft', 'Microsoft.VCRedist.2013.x86', 'Freeware', true],
                ['VC++ Redist 2012 x64', 'Microsoft', 'Microsoft.VCRedist.2012.x64', 'Freeware', true],
                ['VC++ Redist 2012 x86', 'Microsoft', 'Microsoft.VCRedist.2012.x86', 'Freeware', true],
                ['VC++ Redist 2010 x64', 'Microsoft', 'Microsoft.VCRedist.2010.x64', 'Freeware', true],
                ['VC++ Redist 2010 x86', 'Microsoft', 'Microsoft.VCRedist.2010.x86', 'Freeware', true],
                ['VC++ Redist 2008 x64', 'Microsoft', 'Microsoft.VCRedist.2008.x64', 'Freeware', true],
                ['VC++ Redist 2008 x86', 'Microsoft', 'Microsoft.VCRedist.2008.x86', 'Freeware', true],
                ['VC++ Redist 2005 x64', 'Microsoft', 'Microsoft.VCRedist.2005.x64', 'Freeware', true],
                ['VC++ Redist 2005 x86', 'Microsoft', 'Microsoft.VCRedist.2005.x86', 'Freeware', true],
            ],
            'Developer Tools' => [
                ['Python 3', 'Python Software Foundation', 'Python.Python.3.13', 'PSF-2.0', true],
                ['Git', 'Git SCM', 'Git.Git', 'GPL-2.0', true],
                ['FileZilla Client', 'Tim Kosse', 'TimKosse.FileZilla.Client', 'GPL-2.0', true],
                ['WinSCP', 'Martin Prikryl', 'WinSCP.WinSCP', 'GPL-3.0', true],
                ['PuTTY', 'Simon Tatham', 'PuTTY.PuTTY', 'MIT', true],
                ['WinMerge', 'WinMerge Team', 'WinMerge.WinMerge', 'GPL-2.0', true],
                ['Eclipse IDE', 'Eclipse Foundation', 'EclipseFoundation.EclipseIDEforJavaDevelopers', 'EPL-2.0', false],
                ['Visual Studio Code', 'Microsoft', 'Microsoft.VisualStudioCode', 'MIT', true],
                ['Cursor', 'Anysphere', 'Anysphere.Cursor', 'Commercial', true],
                ['Windows Terminal', 'Microsoft', 'Microsoft.WindowsTerminal', 'MIT', true],
            ],
        ];

        $sort = 0;
        foreach ($catalog as $categoryName => $packages) {
            $category = PackageCategory::updateOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName, 'sort_order' => $sort++]
            );

            foreach ($packages as [$name, $vendor, $wingetId, $license, $active]) {
                $package = Package::withTrashed()->firstOrNew(['slug' => Str::slug($name)]);
                $isNew = ! $package->exists;

                $package->fill([
                    'package_category_id' => $category->id,
                    'name'                => $name,
                    'vendor'              => $vendor,
                    'winget_id'           => $wingetId,
                    'license'             => $license,
                    'installer_type'      => InstallerType::Winget,
                    'architecture'        => str_contains($name, 'x86') ? Architecture::X86
                        : (str_contains($name, 'arm64') ? Architecture::Arm64 : Architecture::X64),
                ]);

                if ($isNew) {
                    $package->is_active = $active;
                    if (! $active) {
                        $package->description = 'Seeded inactive: verify the winget ID (winget search) before activating.';
                    }
                }

                $package->save();
            }
        }
    }
}
