<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The loyalty program (decided 2026-11-24, BES Loyalty Program spec).
 *
 *  - Points keep centavos: 1% of a ₱160 order is 1.60 points, so the balance
 *    and the ledger go from whole numbers to two decimals.
 *  - The ledger survives an account deletion without its name, so the Store
 *    Manager's Total Points Issued and Redeemed never drop.
 *  - orders.points_amount: the pesos of the bill paid with points, so an
 *    order's figures still add up (subtotal - discount - points + delivery).
 *  - order_items.redeemed_quantity: how many units of a line were paid with
 *    points (the dish only; its add-ons are paid as usual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('loyalty_points', 10, 2)->default(0)->change();
        });

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->decimal('points_change', 10, 2)->change();
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['order_id', 'reason']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('points_amount', 10, 2)->default(0)->after('vat_exempt_amount');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('redeemed_quantity')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('redeemed_quantity');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('points_amount');
        });

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'reason']);
            $table->dropForeign(['user_id']);
        });

        // Rows kept from deleted accounts cannot go back under a NOT NULL user.
        DB::table('loyalty_transactions')->whereNull('user_id')->delete();

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->integer('points_change')->change();
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->integer('loyalty_points')->default(0)->change();
        });
    }
};
