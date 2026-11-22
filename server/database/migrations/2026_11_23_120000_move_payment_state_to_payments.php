<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decided 2026-11-23: the payment record is the only record of whether an
 * order was paid and what is owed back on it. The order's own payment_status
 * and refund_owed go; each refund moves onto the payment that paid the order.
 *
 * The demo orders OrderSeeder wrote before payment existed are deleted with
 * the seeder. Their ids were kept in a setting, which exists only where the
 * seeder ran; it never runs in production.
 *
 * Refunds owed before today were never paid out by the system, and some may
 * have been settled by hand since, so they become refunds by hand for a Store
 * Agent to check and tick rather than being sent to Xendit now.
 */
return new class extends Migration
{
    private const DEMO_KEY = 'seeding.demo_order_ids';

    public function up(): void
    {
        $this->deleteDemoOrders();

        DB::table('orders')
            ->whereNotNull('refund_owed')
            ->orderBy('id')
            ->select(['id', 'refund_owed'])
            ->chunkById(200, function ($orders) {
                foreach ($orders as $order) {
                    $paid = DB::table('payments')
                        ->where('order_id', $order->id)
                        ->where('payment_status', 'paid')
                        ->whereNotNull('paid_at')
                        ->orderBy('paid_at')
                        ->orderBy('id')
                        ->first();

                    if ($paid === null) {
                        continue;
                    }

                    $owed = round((float) $order->refund_owed, 2);

                    DB::table('payments')->where('id', $paid->id)->update([
                        'refund_amount' => $owed,
                        'refund_status' => $owed > 0 ? 'manual' : null,
                    ]);
                }
            });

        // A second payment on one order was flagged 'pending' and never paid out.
        DB::table('payments')->where('refund_status', 'pending')->update(['refund_status' => 'manual']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'refund_owed']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('refund_owed', 10, 2)->nullable()->after('cancelled_by');
            $table->string('payment_status')->nullable()->after('preparation_time');
        });

        // Rebuilt from the payments, so rolling back loses nothing that is still known.
        DB::table('payments')
            ->where('payment_status', 'paid')
            ->whereNotNull('paid_at')
            ->select(['order_id'])
            ->distinct()
            ->pluck('order_id')
            ->chunk(200)
            ->each(fn ($ids) => DB::table('orders')->whereIn('id', $ids->all())->update(['payment_status' => 'paid']));

        DB::table('payments')
            ->whereNotNull('refund_amount')
            ->select(['order_id', 'refund_amount'])
            ->get()
            ->each(fn ($row) => DB::table('orders')->where('id', $row->order_id)->update(['refund_owed' => $row->refund_amount]));
    }

    private function deleteDemoOrders(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $ids = DB::table('settings')->where('key', self::DEMO_KEY)->value('value');

        if (is_string($ids) && $ids !== '') {
            $list = array_values(array_filter(array_map('intval', explode(',', $ids))));

            // Items, add-ons, payments, holds and notices go with them (cascades).
            foreach (array_chunk($list, 200) as $chunk) {
                DB::table('orders')->whereIn('id', $chunk)->delete();
            }
        }

        DB::table('settings')->where('key', self::DEMO_KEY)->delete();
    }
};
