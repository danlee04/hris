<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin issues a temporary password. Until it is replaced, that account
 * goes nowhere but the page where it can be replaced.
 */
class EnsurePasswordHasBeenChanged
{
    /**
     * The password form lives on settings/security, which itself sits behind
     * Fortify's password.confirm. Every one of these has to stay reachable or
     * the redirect loops.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->must_change_password) {
            return $next($request);
        }

        // Livewire's update endpoint carries every form submission on the page,
        // the password form included. Redirecting it would make the change
        // impossible to complete. Matching that endpoint by URI is not an
        // option — it carries a per-application random prefix.
        if ($request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        if ($request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        return redirect()->route('security.edit')
            ->with('status', __('Please choose your own password before continuing.'));
    }
}
