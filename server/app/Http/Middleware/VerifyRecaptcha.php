<?php

namespace App\Http\Middleware;

use App\Services\Recaptcha\RecaptchaResult;
use App\Services\Recaptcha\RecaptchaService;
use App\Support\NativeApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reCAPTCHA v2 Invisible check, on the routes that need no account and
 * send a code or create something: sign-up, the sign-up code resend, sign-in,
 * forgot password and the guest order (decided 2026-11-22).
 *
 * A person Google is unsure about solves its puzzle in the browser before the
 * request is sent, so there is nothing to step up here: a request either
 * carries a passing token or is refused.
 */
class VerifyRecaptcha
{
    public function __construct(private RecaptchaService $recaptcha) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The native app cannot run reCAPTCHA; its requests go through without
        // a token, and the rate limits on these routes are their protection.
        // The header is spoofable by design.
        if (NativeApp::isNative($request)) {
            return $next($request);
        }

        $token = $request->input('recaptcha_token')
            ?? $request->header('X-Recaptcha-Token');

        $result = $this->recaptcha->verify(is_string($token) ? $token : null, $request->ip());

        if ($result->passed) {
            return $next($request);
        }

        return response()->json([
            'message' => $result->reason === RecaptchaResult::EXPIRED
                ? 'The security check expired. Please try again.'
                : 'Please complete the security check and try again.',
            'error_code' => 'RECAPTCHA_FAILED',
            'reason' => $result->reason,
        ], 422);
    }
}
