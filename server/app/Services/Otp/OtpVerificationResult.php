<?php

namespace App\Services\Otp;

enum OtpVerificationResult
{
    case Verified;
    case Invalid;
    case Expired;
    case TooManyAttempts;
    case NoCode;
}
