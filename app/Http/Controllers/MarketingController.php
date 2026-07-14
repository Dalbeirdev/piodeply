<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\NotificationService;
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
    public function pricing()    { return view('marketing.pricing'); }
    public function contact()    { return view('marketing.contact'); }
    public function privacy()    { return view('marketing.privacy'); }
    public function getStarted() { return view('marketing.get-started'); }

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
}
