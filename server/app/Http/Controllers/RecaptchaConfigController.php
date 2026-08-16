<?php

namespace App\Http\Controllers;

use App\Services\Recaptcha\RecaptchaAction;
use App\Services\Recaptcha\RecaptchaService;

class RecaptchaConfigController extends Controller
{
    public function __construct(private RecaptchaService $recaptcha)
    {
    }

    public function show()
    {
        return response()->json([
            'enabled' => $this->recaptcha->isEnabled(),
            'site_key' => $this->recaptcha->siteKey(),
            'actions' => RecaptchaAction::all(),
        ]);
    }
}
