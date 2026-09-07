<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers that tell a browser what this application is allowed to do.
 *
 * They cost nothing and they close whole classes of attack that no amount of
 * care in the Blade templates can reach: a PDS displayed inside an invisible
 * frame on somebody else's page, a login form quietly re-pointed at another
 * host, a text file served as script because the browser guessed.
 *
 * **There is no `script-src` here, on purpose.** Livewire ships Alpine, and
 * Alpine evaluates its directives at runtime, so the only script policy this
 * application could actually run under is one carrying `unsafe-eval` and
 * `unsafe-inline` — which permits exactly what a script policy exists to
 * forbid, while reading on an audit as though scripts were locked down. A
 * header that claims a protection it does not provide is worse than its
 * absence, because it stops anyone from looking again. Closing this properly
 * means nonces through every Livewire and Flux response, which is its own
 * piece of work.
 *
 * The directives that are here need no nonce and are not weakened by inline
 * code: where the page may be framed, where its forms may post, what its
 * relative URLs resolve against, and whether plugins may run at all.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            // Nobody may frame this application. A PDS behind a transparent
            // overlay is a signature the employee did not know they gave.
            "frame-ancestors 'none'",
            // A <base> tag injected into the page cannot silently repoint every
            // relative URL, including the login form's.
            "base-uri 'self'",
            // A form on this site posts to this site. Credentials do not leave
            // the hospital because a template was tampered with.
            "form-action 'self'",
            // No Flash, no Java, no plugin of any kind.
            "object-src 'none'",
        ]));

        // What frame-ancestors says, said again for browsers that predate it.
        $response->headers->set('X-Frame-Options', 'DENY');

        // An uploaded file served with the wrong type is not promoted to script
        // because the browser sniffed its contents and decided otherwise.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // A URL in this system can name an employee. It does not travel to
        // another host in a Referer header.
        $response->headers->set('Referrer-Policy', 'same-origin');

        // Nothing here needs a camera, a microphone or a location, so nothing
        // here — or anything injected into it — may ask.
        $response->headers->set('Permissions-Policy', implode(', ', [
            'camera=()',
            'microphone=()',
            'geolocation=()',
            'payment=()',
            'usb=()',
        ]));

        // Only over a connection that is already secure. Over plain http every
        // browser ignores it, and a header that looks like protection while
        // doing nothing is how a checklist gets ticked on a system that is
        // still in the clear.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
