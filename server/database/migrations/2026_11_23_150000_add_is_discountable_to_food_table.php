<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a dish can take the Senior/PWD discount, set by the Store Manager on
 * the product form (Discount: Yes / None).
 *
 * Yes is stored as true and None is left null, so nothing is written for a dish
 * that does not take it; every existing dish starts as None (decided
 * 2026-11-23, PRD FR-05.9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food', function (Blueprint $table) {
            $table->boolean('is_discountable')->nullable()->after('is_best_seller');
        });
    }

    public function down(): void
    {
        Schema::table('food', function (Blueprint $table) {
            $table->dropColumn('is_discountable');
        });
    }
};
