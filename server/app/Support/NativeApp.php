<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Requests from the native Android app (mobile/).
 *
 * The app sends `X-Client-App: click-n-chick-android` on every request. Anyone
 * can send that header, so it grants nothing an attacker could not already
 * do: it only lets a request skip reCAPTCHA, which a native app cannot run,
 * and rate limiting stays the protection (PRD FR-01.8, O-06: an accepted gap
 * with no App Check or Play Integrity upgrade planned).
 */
final class NativeApp
{
    public const HEADER = 'X-Client-App';

    public const ANDROID = 'click-n-chick-android';

    /** The product token the app puts in its user agent, for the device list. */
    public const USER_AGENT_TOKEN = 'ClickNChickApp/';

    public static function isNative(Request $request): bool
    {
        return $request->header(self::HEADER) === self::ANDROID;
    }
}
