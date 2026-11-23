<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT a Senior Citizen or PWD order was exempted from (decided 2026-11-23).
 *
 * It is the part of orders.discount_amount that is VAT, so discount_amount
 * keeps meaning everything taken off and totals still add up; the receipt shows
 * the two parts as separate lines. Earlier orders were discounted without the
 * exemption, so 0 is true of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('vat_exempt_amount', 10, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('vat_exempt_amount');
        });
    }
};
