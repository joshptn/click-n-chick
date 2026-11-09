<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Reports\DailySalesReport;
use App\Services\Reports\OperationsSnapshot;
use App\Services\Reports\SalesAnalytics;
use App\Services\Store\StoreAvailability;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The Store Manager's sales reports (UC-REP). Read-only. */
class SalesReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private DailySalesReport $report,
        private SalesAnalytics $analytics,
        private OperationsSnapshot $operations,
        private StoreAvailability $store,
    ) {}

    /** GET /api/admin/reports/daily-sales?date=2026-11-09 */
    public function daily(Request $request)
    {
        $this->authorize('isSuperAdmin', Order::class);

        $today = $this->store->now()->toDateString();

        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'order_type' => ['sometimes', 'string', Rule::in(['all', StoreAvailability::TYPE_PICKUP, StoreAvailability::TYPE_DELIVERY])],
            'kind' => ['sometimes', 'string', Rule::in(['all', 'regular', 'advance'])],
        ], [
            'date.before_or_equal' => 'A sales report cannot be run for a future date.',
        ]);

        return response()->json($this->report->for($validated['date'] ?? $today, $validated));
    }

    /** GET /api/admin/reports/analytics */
    public function analytics()
    {
        $this->authorize('isSuperAdmin', Order::class);

        return response()->json($this->analytics->now());
    }

    /** GET /api/admin/reports/operations */
    public function operations()
    {
        $this->authorize('isSuperAdmin', Order::class);

        return response()->json($this->operations->now());
    }
}
