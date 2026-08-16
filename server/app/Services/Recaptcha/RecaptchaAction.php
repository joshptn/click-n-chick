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

    public const TWO_FACTOR_CHALLENGE = 'two_factor_challenge';

    public const TWO_FACTOR_ENABLE = 'two_factor_enable';

    public const PASSWORD_FORGOT = 'password_forgot';

    public const PASSWORD_RESET = 'password_reset';

    public const PASSWORD_CHANGE = 'password_change';

    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'register' => self::REGISTER,
            'login' => self::LOGIN,
            'otpResend' => self::OTP_RESEND,
            'twoFactorChallenge' => self::TWO_FACTOR_CHALLENGE,
            'twoFactorEnable' => self::TWO_FACTOR_ENABLE,
            'passwordForgot' => self::PASSWORD_FORGOT,
            'passwordReset' => self::PASSWORD_RESET,
            'passwordChange' => self::PASSWORD_CHANGE,
        ];
    }
}
