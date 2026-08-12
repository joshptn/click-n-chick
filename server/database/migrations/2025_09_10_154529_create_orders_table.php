<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Nullable for guest checkout.
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            // Nullable: a guest has no saved address.
            $table->foreignId('address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->string('order_number')->nullable()->unique();
            $table->string('order_type')->nullable();
            $table->dateTime('scheduled_for')->nullable();
            $table->decimal('total_price', 10, 2);
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('status')->default('pending');
            $table->string('estimated_time_of_completion')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('guest_name')->nullable();
            $table->string('guest_phone')->nullable();
            $table->string('guest_email')->nullable();
            $table->string('full_address')->nullable();
            $table->string('longitude')->nullable();
            $table->string('latitude')->nullable();
            $table->string('location')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('proof_of_payment')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('order_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
