<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a dish from the catalogue is a soft delete.
 *
 * order_items.food_id cascades on delete, so removing the row would take every
 * past order line for the dish with it - rewriting order history, receipts and
 * the records the store keeps. The row stays; the catalogue, the menu, carts
 * and checkout stop seeing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('food', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
