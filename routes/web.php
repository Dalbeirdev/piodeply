<?php

use Illuminate\Support\Facades\Route;

// Public marketing site.
Route::get('/', [\App\Http\Controllers\MarketingController::class, 'home'])->name('home');
Route::get('/sitemap.xml', [\App\Http\Controllers\SeoController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [\App\Http\Controllers\SeoController::class, 'robots'])->name('robots');

Route::get('/features', [\App\Http\Controllers\MarketingController::class, 'features'])->name('features');
Route::get('/about', [\App\Http\Controllers\MarketingController::class, 'about'])->name('about');
Route::get('/pricing', [\App\Http\Controllers\MarketingController::class, 'pricing'])->name('pricing');
Route::get('/contact', [\App\Http\Controllers\MarketingController::class, 'contact'])->name('contact');
Route::get('/privacy', [\App\Http\Controllers\MarketingController::class, 'privacy'])->name('privacy');
Route::get('/get-started', [\App\Http\Controllers\MarketingController::class, 'getStarted'])->name('get-started');
Route::get('/brand', [\App\Http\Controllers\MarketingController::class, 'brand'])->name('brand');
Route::post('/leads', [\App\Http\Controllers\MarketingController::class, 'storeLead'])
    ->middleware('throttle:6,1')->name('leads.store');
Route::post('/quote', [\App\Http\Controllers\MarketingController::class, 'storeQuote'])
    ->middleware('throttle:6,1')->name('quotes.store');

// Billing (Stripe Checkout). Webhook is CSRF-exempt (see bootstrap/app.php)
// and HMAC-verified instead.
Route::post('/billing/checkout', [\App\Http\Controllers\BillingController::class, 'checkout'])
    ->middleware('throttle:12,1')->name('billing.checkout');
Route::get('/billing/success', [\App\Http\Controllers\BillingController::class, 'success'])->name('billing.success');
Route::post('/billing/webhook', [\App\Http\Controllers\BillingController::class, 'webhook'])->name('billing.webhook');

// Cashier/subscription webhook — signature-verified, idempotent, logged.
Route::post('/stripe/webhook', \App\Http\Controllers\StripeWebhookController::class)->name('stripe.webhook');

// Public agent download (the token is the secret; keys are never embedded).
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/download/agent/{token}', [\App\Http\Controllers\AgentDownloadController::class, 'script'])
        ->name('agent.download');
    Route::get('/download/agent/{token}/binary', [\App\Http\Controllers\AgentDownloadController::class, 'binary'])
        ->name('agent.download.binary');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', \App\Livewire\Dashboard::class)->name('dashboard');

    Route::get('/admin/users', \App\Livewire\Admin\ManageUsers::class)
        ->middleware('permission:users.view')
        ->name('admin.users');

    Route::get('/admin/roles', \App\Livewire\Admin\ManageRoles::class)
        ->middleware('permission:roles.manage')
        ->name('admin.roles');

    Route::get('/admin/notifications', \App\Livewire\Admin\NotificationChannels::class)
        ->middleware('permission:settings.manage')
        ->name('admin.notifications');

    Route::get('/activity', \App\Livewire\Admin\ActivityIndex::class)
        ->middleware('permission:activity.view')
        ->name('activity.index');

    Route::get('/admin/settings', \App\Livewire\Admin\SettingsPage::class)
        ->middleware('permission:settings.manage')
        ->name('admin.settings');

    // Subscription & billing (per MSP account).
    Route::get('/billing/subscription', \App\Livewire\Billing\Subscription::class)
        ->middleware('permission:settings.manage')
        ->name('billing.subscription');

    Route::get('/billing/invoices', \App\Livewire\Billing\Portal::class)
        ->middleware('permission:settings.manage')
        ->name('billing.invoices');
    Route::get('/billing/invoices/{invoiceId}/download', [\App\Http\Controllers\BillingInvoiceController::class, 'download'])
        ->middleware('permission:settings.manage')
        ->name('billing.invoices.download');

    Route::get('/admin/webhooks', \App\Livewire\Admin\WebhookEvents::class)
        ->middleware('permission:settings.manage')
        ->name('admin.webhooks');

    Route::get('/admin/coupons', \App\Livewire\Admin\Coupons::class)
        ->middleware('permission:settings.manage')
        ->name('admin.coupons');

    Route::get('/admin/billing-overview', \App\Livewire\Admin\BillingDashboard::class)
        ->middleware('permission:settings.manage')
        ->name('admin.billing-overview');
    Route::get('/admin/billing-overview/export', [\App\Http\Controllers\BillingExportController::class, 'payments'])
        ->middleware('permission:settings.manage')
        ->name('billing.export');

    Route::get('/admin/affiliates', \App\Livewire\Admin\Affiliates::class)
        ->middleware('permission:settings.manage')
        ->name('admin.affiliates');
    Route::get('/admin/affiliates/export', [\App\Http\Controllers\AffiliateExportController::class, 'commissions'])
        ->middleware('permission:settings.manage')
        ->name('affiliates.export');

    // An affiliate's own dashboard (any signed-in user; shows a notice if not one).
    Route::get('/affiliate', \App\Livewire\Affiliate\Dashboard::class)->name('affiliate.dashboard');

    Route::get('/admin/content', \App\Livewire\Admin\ManageContent::class)
        ->middleware('permission:settings.manage')
        ->name('admin.content');

    Route::get('/admin/billing', \App\Livewire\Admin\BillingSettings::class)
        ->middleware('permission:settings.manage')
        ->name('admin.billing');

    Route::post('/admin/impersonate/{user}', [\App\Http\Controllers\ImpersonationController::class, 'start'])
        ->middleware('role:Super Admin')
        ->name('impersonate.start');
    Route::post('/impersonate/leave', [\App\Http\Controllers\ImpersonationController::class, 'leave'])
        ->name('impersonate.leave');

    Route::middleware('permission:clients.view')->group(function () {
        Route::get('/clients', \App\Livewire\Clients\ClientsIndex::class)->name('clients.index');
        Route::get('/clients/create', \App\Livewire\Clients\ClientForm::class)->name('clients.create');
        Route::get('/clients/{client}/edit', \App\Livewire\Clients\ClientForm::class)->name('clients.edit');
    });

    Route::middleware('permission:projects.view')->group(function () {
        Route::get('/projects', \App\Livewire\Projects\ProjectsIndex::class)->name('projects.index');
        Route::get('/projects/create', \App\Livewire\Projects\ProjectForm::class)->name('projects.create');
        Route::get('/projects/{project}/edit', \App\Livewire\Projects\ProjectForm::class)->name('projects.edit');
        Route::get('/projects/{project}/enrollment', \App\Livewire\Projects\ProjectEnrollment::class)
            ->name('projects.enrollment');
    });

    // Everything the website sent us. Gated inside the component too.
    Route::get('/admin/enquiries', \App\Livewire\Admin\LeadsIndex::class)->name('admin.leads');

    // SMTP without an SSH session. Gated inside the component too.
    Route::get('/admin/email', \App\Livewire\Admin\MailSettings::class)
        ->middleware('permission:settings.manage')->name('admin.mail');

    Route::middleware('permission:computers.view')->group(function () {
        Route::get('/computers', \App\Livewire\Computers\ComputersIndex::class)->name('computers.index');
        Route::get('/computers/{computer}', \App\Livewire\Computers\ComputerShow::class)->name('computers.show');
        Route::get('/computers/{computer}/edit', \App\Livewire\Computers\ComputerEdit::class)->name('computers.edit');
    });

    Route::middleware('permission:packages.view')->group(function () {
        Route::get('/packages', \App\Livewire\Packages\PackagesIndex::class)->name('packages.index');
        Route::get('/packages/create', \App\Livewire\Packages\PackageForm::class)->name('packages.create');
        Route::get('/packages/{package}', \App\Livewire\Packages\PackageShow::class)->name('packages.show');
        Route::get('/packages/{package}/edit', \App\Livewire\Packages\PackageForm::class)->name('packages.edit');
    });

    Route::middleware('permission:deployments.view')->group(function () {
        Route::get('/deployments', \App\Livewire\Deployments\DeploymentsIndex::class)->name('deployments.index');
    });

    Route::middleware('permission:reports.view')->group(function () {
        Route::get('/reports', \App\Livewire\Reports\ReportsIndex::class)->name('reports.index');
        Route::get('/reports/compliance', \App\Livewire\Reports\ComplianceReport::class)->name('reports.compliance');
        Route::get('/reports/deployments', \App\Livewire\Reports\DeploymentsReport::class)->name('reports.deployments');
        Route::get('/reports/computers', \App\Livewire\Reports\ComputersReport::class)->name('reports.computers');
    });

    Route::middleware('permission:policies.view')->group(function () {
        Route::get('/policies', \App\Livewire\Policies\PoliciesIndex::class)->name('policies.index');
        Route::get('/policies/create', \App\Livewire\Policies\PolicyForm::class)->name('policies.create');
        Route::get('/policies/{policy}', \App\Livewire\Policies\PolicyShow::class)->name('policies.show');
        Route::get('/policies/{policy}/edit', \App\Livewire\Policies\PolicyForm::class)->name('policies.edit');

        Route::get('/browser-policies', \App\Livewire\BrowserPolicies\BrowserPoliciesIndex::class)->name('browser-policies.index');
        Route::get('/browser-policies/create', \App\Livewire\BrowserPolicies\BrowserPolicyForm::class)->name('browser-policies.create');
        Route::get('/browser-policies/{policy}', \App\Livewire\BrowserPolicies\BrowserPolicyShow::class)->name('browser-policies.show');
        Route::get('/browser-policies/{policy}/edit', \App\Livewire\BrowserPolicies\BrowserPolicyForm::class)->name('browser-policies.edit');
    });
});
