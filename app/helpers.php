<?php

use App\Services\SettingsService;
use Illuminate\Support\Str;

/*
 * UI terminology helpers. The client→machines grouping is "project" in code,
 * routes, database and agent API - forever. What USERS call it is a runtime
 * setting (branding.project_term, default "Site"), because an MSP's clients
 * may organise by site, department, location or team. These helpers are the
 * single place the two worlds meet: every Blade view and flash message says
 * project_term(), never the literal word.
 */

if (! function_exists('project_term')) {
    /** The singular display term, e.g. "Site". */
    function project_term(): string
    {
        return (string) app(SettingsService::class)->get('branding.project_term');
    }
}

if (! function_exists('project_terms')) {
    /** The plural display term, e.g. "Sites". */
    function project_terms(): string
    {
        return Str::plural(project_term());
    }
}

if (! function_exists('project_term_lower')) {
    /** Lowercase singular for mid-sentence use, e.g. "site". */
    function project_term_lower(): string
    {
        return Str::lower(project_term());
    }
}

if (! function_exists('project_terms_lower')) {
    /** Lowercase plural for mid-sentence use, e.g. "sites". */
    function project_terms_lower(): string
    {
        return Str::lower(project_terms());
    }
}
