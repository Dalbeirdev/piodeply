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

/*
 * Same contract for the two rule types. Code, routes and DB keep "policy";
 * what users read is branding.policy_term / branding.browser_policy_term.
 */

if (! function_exists('policy_term')) {
    /** Singular display term for a software rule, e.g. "Automation". */
    function policy_term(): string
    {
        return (string) app(SettingsService::class)->get('branding.policy_term');
    }
}

if (! function_exists('policy_terms')) {
    function policy_terms(): string
    {
        return Str::plural(policy_term());
    }
}

if (! function_exists('policy_term_lower')) {
    function policy_term_lower(): string
    {
        return Str::lower(policy_term());
    }
}

if (! function_exists('policy_terms_lower')) {
    function policy_terms_lower(): string
    {
        return Str::lower(policy_terms());
    }
}

if (! function_exists('browser_policy_term')) {
    /** Singular display term for a browser rule, e.g. "Browser Control". */
    function browser_policy_term(): string
    {
        return (string) app(SettingsService::class)->get('branding.browser_policy_term');
    }
}

if (! function_exists('browser_policy_terms')) {
    function browser_policy_terms(): string
    {
        return Str::plural(browser_policy_term());
    }
}

if (! function_exists('browser_policy_term_lower')) {
    function browser_policy_term_lower(): string
    {
        return Str::lower(browser_policy_term());
    }
}

if (! function_exists('trial_days')) {
    /**
     * The free-trial length the operator has configured (Admin → Billing).
     * Copy that promises a trial must read it from here — a page still
     * saying "14-day" after the setting moved is a promise Stripe will not
     * honour.
     */
    function trial_days(): int
    {
        return app(SettingsService::class)->trialDays();
    }
}

if (! function_exists('trial_phrase')) {
    /** The trial as buyers see it, e.g. "14-day free trial". */
    function trial_phrase(): string
    {
        return trial_days() > 0
            ? trial_days().'-day free trial'
            : 'no free trial';
    }
}
