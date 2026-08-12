<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // Nullable for guest discount claims.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // senior, pwd
            $table->string('discount_type');
            $table->decimal('discount_percentage', 5, 2)->default(0);

            // VAT is exempted separately from the statutory discount percentage.
            $table->boolean('vat_exempt')->default(false);
            $table->decimal('vat_exempt_amount', 10, 2)->default(0);

            $table->string('id_image')->nullable();
            $table->string('discount_status')->default('pending');

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index('discount_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
