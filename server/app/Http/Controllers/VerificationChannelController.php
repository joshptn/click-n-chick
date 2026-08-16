<?php

namespace App\Http\Controllers;

use App\Services\Verification\ChannelRegistry;

class VerificationChannelController extends Controller
{
    public function __construct(private ChannelRegistry $channels)
    {
    }

    public function index()
    {
        return response()->json([
            'channels' => $this->channels->describe(),
        ]);
    }
}
