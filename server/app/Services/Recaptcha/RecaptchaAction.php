<?php

namespace App\Services\Recaptcha;

/**
 * The v3 action names, defined once.
 *
 * Routes attach these via the `recaptcha:` middleware and the config endpoint
 * hands the same list to the browser, so a token is always minted under the
 * action the route will check it against. A mismatch would otherwise surface
 * only as a 422 in production, once real credentials are in play.
 */
final class RecaptchaAction
{
    public const REGISTER = 'register';

    public const LOGIN = 'login';

    public const OTP_RESEND = 'otp_resend';

    /**
     * Registration OTP redemption.
     *
     * Guarded like every other public endpoint that hands back a token. The
     * per-code attempt cap and the throttle already make brute force
     * impractical; this closes the gap of being the one unguarded door in a set
     * where every neighbour is watched.
     */
    public const OTP_VERIFY = 'otp_verify';

    public const TWO_FACTOR_CHALLENGE = 'two_factor_challenge';

    public const TWO_FACTOR_ENABLE = 'two_factor_enable';

    public const PASSWORD_FORGOT = 'password_forgot';

    public const PASSWORD_RESET = 'password_reset';

    public const PASSWORD_CHANGE = 'password_change';

    /**
     * Final order submission (FR-02.11, BR-32, UC-GUEST-005).
     *
     * Its own action rather than reusing LOGIN, so a token minted on the
     * sign-in form cannot be replayed to place orders.
     */
    public const PLACE_ORDER = 'place_order';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'register' => self::REGISTER,
            'login' => self::LOGIN,
            'otpResend' => self::OTP_RESEND,
            'otpVerify' => self::OTP_VERIFY,
            'twoFactorChallenge' => self::TWO_FACTOR_CHALLENGE,
            'twoFactorEnable' => self::TWO_FACTOR_ENABLE,
            'passwordForgot' => self::PASSWORD_FORGOT,
            'passwordReset' => self::PASSWORD_RESET,
            'passwordChange' => self::PASSWORD_CHANGE,
            'placeOrder' => self::PLACE_ORDER,
        ];
    }
}
