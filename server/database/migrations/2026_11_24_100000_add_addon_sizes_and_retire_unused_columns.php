<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-on management (decided 2026-11-24).
 *
 *  - Sizes belong to add-ons, not dishes: an add-on comes in Small, Medium and
 *    Large, each at its own price, or in no size at all. The Store Agent
 *    switches a size off when it runs out (`is_available`).
 *  - A cart line and an order line keep the size the customer picked.
 *  - An add-on is soft-deleted, so past orders keep their add-on lines; the
 *    join tables delete theirs on a hard delete, which never happens now.
 *  - Retired: `addons.description`, `food.prep_time` and `food.size`, which
 *    nothing uses any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            // small | medium | large (Addon::SIZES)
            $table->string('size', 10);
            $table->decimal('price', 10, 2);
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['addon_id', 'size']);
        });

        Schema::table('addons', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->softDeletes();
        });

        Schema::table('cart_item_addons', function (Blueprint $table) {
            $table->string('size', 10)->nullable()->after('addon_id');
        });

        Schema::table('order_item_addons', function (Blueprint $table) {
            $table->string('size', 10)->nullable()->after('addon_id');
        });

        Schema::table('food', function (Blueprint $table) {
            $table->dropColumn(['prep_time', 'size']);
        });
    }

    public function down(): void
    {
        Schema::table('food', function (Blueprint $table) {
            $table->integer('prep_time')->nullable();
            $table->enum('size', ['small', 'medium', 'large'])->nullable();
        });

        Schema::table('order_item_addons', function (Blueprint $table) {
            $table->dropColumn('size');
        });

        Schema::table('cart_item_addons', function (Blueprint $table) {
            $table->dropColumn('size');
        });

        Schema::table('addons', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->string('description')->nullable();
        });

        Schema::dropIfExists('addon_sizes');
    }
};
