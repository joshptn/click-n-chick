<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store-Manager-governed system configuration (UC-ADMIN-003).
 *
 * Key/value rather than a column per setting, because the settings this will
 * hold arrive one module at a time - the statutory discount rate first
 * (BR-27), then delivery rates (BR-14), operating hours (BR-15), sales
 * performance thresholds (BR-28), loyalty values (BR-24). A column per setting
 * means a migration per setting; a row per setting means none.
 *
 * The trade-off is that values are untyped text and the schema cannot enforce
 * ranges. That constraint lives in the Setting accessors and the controller
 * instead - see Discount::currentPercentage(), which clamps to the statutory
 * floor on READ so a bad row cannot take the business below the legal rate
 * even if one is written directly to the database.
 *
 * Ordered before the discounts table so its seedable default is available to
 * anything that follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            // Who last changed it. Administrative actions must be attributable
            // (NFR-10); this is the audit trail for configuration.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
