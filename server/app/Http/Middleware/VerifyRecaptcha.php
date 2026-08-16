<?php

namespace App\Http\Middleware;

use App\Services\Recaptcha\RecaptchaResult;
use App\Services\Recaptcha\RecaptchaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyRecaptcha
{
    public function __construct(private RecaptchaService $recaptcha)
    {
    }

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $token = $request->input('recaptcha_token')
            ?? $request->header('X-Recaptcha-Token');

        $result = $this->recaptcha->verify(
            is_string($token) ? $token : null,
            $action,
            $request->ip(),
        );

        if ($result->passed) {
            return $next($request);
        }

        return response()->json([
            'message' => $result->reason === RecaptchaResult::LOW_SCORE
                ? 'This request looked automated. Please try again.'
                : 'We could not verify that this request came from a browser. Please reload the page and try again.',
            'error_code' => 'RECAPTCHA_FAILED',
            'reason' => $result->reason,
        ], 422);
    }
}
