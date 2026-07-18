<?php

namespace App\Http\Middleware;

use App\Services\AffiliateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Captures ?ref=CODE on any page: logs a click for a real affiliate and drops
 * a 30-day cookie so a later signup can be attributed. Unknown codes are
 * ignored silently.
 */
class CaptureReferral
{
    public function __construct(private readonly AffiliateService $affiliates)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $code = $request->query('ref');
        $response = $next($request);

        if (is_string($code) && trim($code) !== '' && $request->isMethod('GET')) {
            // Only record + set the cookie for a code that maps to a live affiliate.
            $click = $this->affiliates->recordClick(
                $code, $request->ip(), $request->path(), $request->headers->get('referer')
            );

            if ($click !== null) {
                $response->headers->setCookie(
                    Cookie::create('pd_ref', trim($code), now()->addDays(30), '/')
                );
            }
        }

        return $response;
    }
}
