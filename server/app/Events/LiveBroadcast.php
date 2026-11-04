<?php

namespace App\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * How every realtime event leaves the application.
 *
 * Now: published to Reverb by the request that caused it, one loopback HTTP
 * call, instead of waiting in the database queue for a worker to poll it.
 *
 * After commit: an event raised inside a transaction goes out once the change
 * is saved, so a page that refetches on it reads the new state, and a rolled
 * back change announces nothing.
 *
 * Rescued: the change is already saved when this runs, so a Reverb that is down
 * or slow is reported to the log instead of failing the request. Pages catch up
 * when their socket reconnects (RealtimeContext) or on their next poll.
 */
interface LiveBroadcast extends ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue {}
