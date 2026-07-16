<?php

namespace App\Services;

use App\Models\Project;

/**
 * Renders ready-to-run enrollment scripts for a project.
 *
 * The project's API key is never stored in a form we can read back (it is
 * hashed), so the operator supplies it and we render around it. Nothing here
 * persists the key.
 */
class EnrollmentScriptService
{
    /**
     * The agent version a machine must be at or above. Bump alongside
     * agent/src/PioDeploy.Agent/PioDeploy.Agent.csproj — it is what makes
     * the GPO script upgrade an already-enrolled fleet rather than skip it.
     */
    public const CURRENT_AGENT_VERSION = '1.3.1';

    /** Shown when the operator has not pasted their key yet. */
    public const KEY_PLACEHOLDER = 'pio_PASTE_YOUR_PROJECT_API_KEY_HERE';

    /** @return array<string, array{label: string, filename: string, language: string, body: string}> */
    public function all(Project $project, ?string $apiKey): array
    {
        return [
            'gpo' => [
                'label'    => 'Group Policy (Active Directory)',
                'filename' => 'Install-PioDeployAgent.ps1',
                'language' => 'powershell',
                'body'     => $this->render('gpo', $project, $apiKey),
            ],
            'intune' => [
                'label'    => 'Intune / Entra',
                'filename' => 'Install-PioDeployAgent-Intune.ps1',
                'language' => 'powershell',
                'body'     => $this->render('intune', $project, $apiKey),
            ],
            'rmm' => [
                'label'    => 'RMM / one-liner',
                'filename' => 'install-piodeploy-agent.ps1',
                'language' => 'powershell',
                'body'     => $this->render('rmm', $project, $apiKey),
            ],
            'single' => [
                'label'    => 'Single machine',
                'filename' => 'install-piodeploy-agent.ps1',
                'language' => 'powershell',
                'body'     => $this->render('single', $project, $apiKey),
            ],
        ];
    }

    /**
     * These templates are PowerShell, not HTML, so they interpolate unescaped
     * — Blade's {{ }} would turn an apostrophe into &#039; and corrupt the
     * script. Everything variable is therefore made safe here instead, for
     * the syntax it actually lands in.
     */
    private function render(string $method, Project $project, ?string $apiKey): string
    {
        return trim(view("agent.enrollment.{$method}", [
            'project'    => $project,
            'name'       => $this->comment($project->name),
            'company'    => $this->comment($project->client->company_name),
            'apiKey'     => $this->key($apiKey),
            'scriptUrl'  => route('agent.download', $project->download_token),
            'minVersion' => self::CURRENT_AGENT_VERSION,
        ])->render());
    }

    /**
     * A single-quoted PowerShell literal: doubling the quote is the only
     * escape it has, so a pasted key cannot close the string and run.
     */
    private function key(?string $apiKey): string
    {
        $key = $apiKey !== null && trim($apiKey) !== '' ? trim($apiKey) : self::KEY_PLACEHOLDER;

        // A newline would end the statement regardless of quoting.
        $key = str_replace(["\r", "\n"], '', $key);

        return str_replace("'", "''", $key);
    }

    /**
     * Text bound for the <# … #> banner. A project named "#>" would close
     * the comment and turn the rest of the banner into code.
     */
    private function comment(string $text): string
    {
        return trim(str_replace(['#>', "\r", "\n"], ['# >', ' ', ' '], $text));
    }
}
