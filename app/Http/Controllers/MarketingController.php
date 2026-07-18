<?php

namespace App\Http\Controllers;

use App\Models\EnterpriseQuote;
use App\Models\Lead;
use App\Models\QuoteMessage;
use App\Services\NotificationService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public marketing site. Static pages plus a shared lead-capture endpoint
 * for the contact and request-access forms (public self-registration is
 * intentionally disabled — accounts are provisioned by staff).
 */
class MarketingController extends Controller
{
    public function home()       { return view('marketing.home'); }
    public function about()      { return view('marketing.about'); }
    public function pricing(PricingService $pricing)
    {
        return view('marketing.pricing', [
            'plans'               => $pricing->plans(),
            'enterpriseThreshold' => PricingService::ENTERPRISE_THRESHOLD,
        ]);
    }
    public function contact()    { return view('marketing.contact'); }
    public function privacy()    { return view('marketing.privacy'); }
    public function getStarted() { return view('marketing.get-started'); }
    public function brand()      { return view('marketing.brand'); }

    public function storeLead(Request $request, NotificationService $notifications)
    {
        $validated = $request->validate([
            'type'        => ['required', Rule::in(['contact', 'access_request'])],
            'name'        => ['required', 'string', 'max:120'],
            'email'       => ['required', 'email', 'max:190'],
            'company'     => ['nullable', 'string', 'max:150'],
            'fleet_size'  => ['nullable', 'string', 'max:20'],
            'message'     => ['nullable', 'string', 'max:2000'],
            'redirect_to' => ['required', Rule::in(['contact', 'get-started'])],
        ]);

        $lead = Lead::create([
            'type'       => $validated['type'],
            'name'       => $validated['name'],
            'email'      => $validated['email'],
            'company'    => $validated['company'] ?? null,
            'fleet_size' => $validated['fleet_size'] ?? null,
            'message'    => $validated['message'] ?? null,
            'ip'         => $request->ip(),
        ]);

        // Alert the team via any channel subscribed to lead events.
        $label = $lead->type === 'access_request' ? 'Access request' : 'Contact message';
        $notifications->notify('lead.received', "{$label} from {$lead->name}", [
            'name'       => $lead->name,
            'email'      => $lead->email,
            'company'    => $lead->company,
            'fleet_size' => $lead->fleet_size,
            'message'    => $lead->message,
        ]);

        return redirect()->route($validated['redirect_to'])->with('lead_ok', true);
    }

    /**
     * Enterprise "request a quote" from the pricing page — fleets larger than
     * the biggest fixed plan. Stored for the sales team and announced on every
     * subscribed notification channel. Public + rate limited (see routes).
     */
    public function storeQuote(Request $request, NotificationService $notifications)
    {
        $validated = $request->validate([
            'company_name'    => ['required', 'string', 'max:150'],
            'contact_name'    => ['required', 'string', 'max:120'],
            'email'           => ['required', 'email', 'max:190'],
            'phone'           => ['nullable', 'string', 'max:40'],
            'country'         => ['nullable', 'string', 'max:80'],
            'device_count'    => ['required', 'integer', 'min:1', 'max:10000000'],
            'current_rmm'     => ['nullable', 'string', 'max:120'],
            'expected_growth' => ['nullable', 'string', 'max:120'],
            'notes'           => ['nullable', 'string', 'max:2000'],
        ]);

        $quote = EnterpriseQuote::create($validated + ['status' => 'new', 'ip' => $request->ip()]);

        // Seed the internal thread so the admin view has a first entry.
        QuoteMessage::create([
            'enterprise_quote_id' => $quote->id,
            'author'              => 'system',
            'body'                => "Quote requested for {$quote->device_count} devices via the pricing page.",
        ]);

        $notifications->notify('quote.received', "Enterprise quote from {$quote->company_name}", [
            'company'  => $quote->company_name,
            'contact'  => $quote->contact_name,
            'email'    => $quote->email,
            'devices'  => number_format($quote->device_count),
            'current'  => $quote->current_rmm,
            'growth'   => $quote->expected_growth,
        ]);

        return redirect()->route('pricing')->with('quote_ok', true)->withFragment('enterprise');
    }
}
