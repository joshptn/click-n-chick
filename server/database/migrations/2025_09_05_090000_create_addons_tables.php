<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->string('addon_name');
            $table->decimal('addon_price', 10, 2)->default(0);
            $table->boolean('availability')->default(true);
            // The heading an add-on sits under on the item detail modal
            // ('Drinks', 'Sides'). Free text rather than an enum so a new
            // grouping needs no migration.
            $table->string('addon_group')->default('Extras');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('addon_group');
        });

        Schema::create('addon_food', function (Blueprint $table) {
            $table->id();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('food')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['addon_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_food');
        Schema::dropIfExists('addons');
    }
};
