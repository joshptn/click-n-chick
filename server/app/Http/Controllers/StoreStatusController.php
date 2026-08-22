<?php

namespace App\Http\Controllers;

use App\Services\Store\StoreAvailability;

/**
 * Whether the store is trading (UC-DEL-007, UC-OPS-012).
 *
 * Public: the header badge and the landing page both show it before anyone
 * signs in, and it discloses nothing an opening-hours sign on the door does
 * not.
 */
class StoreStatusController extends Controller
{
    public function __construct(private StoreAvailability $store) {}

    /** GET /api/store/status */
    public function show()
    {
        return response()->json($this->store->snapshot());
    }
}
