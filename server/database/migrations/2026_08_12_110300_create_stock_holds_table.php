<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_id')->constrained('food')->cascadeOnDelete();
            // Null until the hold is attached to a confirmed order.
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();

            $table->integer('quantity');
            // holding, confirmed, released, expired
            $table->string('status')->default('holding');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // FCFS sweeps query by status + expiry.
            $table->index(['status', 'expires_at']);
            $table->index(['food_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_holds');
    }
};
