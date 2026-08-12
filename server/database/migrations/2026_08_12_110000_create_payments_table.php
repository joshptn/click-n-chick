<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // Nullable for guest payments.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('pending');

            // Correlation: ties a local payment to the provider's session.
            $table->string('provider_reference_id')->nullable();
            // Guards against duplicate webhook processing.
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();

            // Refunds.
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->string('refund_status')->nullable();
            $table->string('refund_reference_id')->nullable();
            $table->timestamp('refunded_at')->nullable();

            // Manual payment methods only: which admin confirmed it.
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index('payment_status');
            $table->index('provider_reference_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
