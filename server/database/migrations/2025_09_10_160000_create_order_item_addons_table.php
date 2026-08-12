<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('addon_id')->constrained('addons')->cascadeOnDelete();
            // Snapshot of the add-on price at order time.
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['order_item_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_addons');
    }
};
