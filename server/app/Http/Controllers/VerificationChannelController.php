<?php

namespace App\Http\Controllers;

use App\Services\Verification\ChannelRegistry;

/**
 * Exposes which verification channels can actually deliver right now.
 *
 * Public because registration needs it before any token exists. The frontend
 * renders its phone/email pickers from this rather than a hardcoded flag, so
 * restoring SMS is a configuration change with no follow-up UI work.
 */
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
