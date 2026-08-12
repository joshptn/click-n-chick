<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Nullable: not every transaction ties to an order (manual adjustments).
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();

            // Positive = earned, negative = redeemed. users.loyalty_points is the
            // running balance derived from this ledger.
            $table->integer('points_change');
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
