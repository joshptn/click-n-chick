<?php

namespace App\Http\Middleware;

use App\Services\Recaptcha\RecaptchaResult;
use App\Services\Recaptcha\RecaptchaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyRecaptcha
{
    /**
     * Request attribute set when a low score was allowed through for the
     * controller to answer with a step-up challenge instead of a refusal.
     *
     * An attribute rather than an input key: inputs come from the client, and
     * a caller must not be able to fake "reCAPTCHA said I am suspicious" - or,
     * worse, fake its absence.
     */
    public const LOW_SCORE_ATTRIBUTE = 'recaptcha.low_score';

    /** Opt-in flag naming the step-up mode, used as `recaptcha:login,step-up`. */
    private const STEP_UP_MODE = 'step-up';

    public function __construct(private RecaptchaService $recaptcha) {}

    public function handle(Request $request, Closure $next, string $action, ?string $mode = null): Response
    {
        $token = $request->input('recaptcha_token')
            ?? $request->header('X-Recaptcha-Token');

        $result = $this->recaptcha->verify(
            is_string($token) ? $token : null,
            $action,
            $request->ip(),
        );

        // Always clear it first. Without this, a client that sent
        // `recaptcha.low_score` as a form field could pre-seed the attribute
        // bag on a request that scored fine.
        $request->attributes->remove(self::LOW_SCORE_ATTRIBUTE);

        if ($result->passed) {
            return $next($request);
        }

        /**
         * FR-01.15 / BR-35: a low score means a real browser that looked
         * suspicious, so the requester is sent to an OTP step-up rather than
         * turned away. The controller decides that, because only it knows
         * whether the credentials were even correct - issuing a challenge
         * before checking them would leak which accounts exist.
         *
         * Confined to LOW_SCORE on purpose. A missing token, an unverifiable
         * token, or a token minted for another action all mean a script or a
         * tampered request rather than a suspicious human; letting those reach
         * the step-up would hand an attacker a free way to make the system send
         * OTPs, which costs real money on the SMS channel.
         */
        if ($mode === self::STEP_UP_MODE && $result->reason === RecaptchaResult::LOW_SCORE) {
            $request->attributes->set(self::LOW_SCORE_ATTRIBUTE, true);

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
