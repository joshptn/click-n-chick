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
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            // ERD shape: items hang off a cart. Nullable so the existing
            // user_id path keeps working during the transition.
            $table->foreignId('cart_id')->nullable()->constrained('cart')->cascadeOnDelete();
            // Nullable for guest carts, which are identified by cart.guest_token.
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('food_id')->constrained()->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('parent_cart_item_id')->nullable(); // points to cart_items.id
            $table->foreign('parent_cart_item_id')->references('id')->on('cart_items')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
