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
        Schema::create('food', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('thumbnail');
            $table->string('food_name');
            $table->integer('price');
            $table->boolean('available')->default(true);
            $table->integer('stock_quantity')->nullable();
            $table->boolean('is_available')->default(true);
            $table->integer('prep_time')->nullable();
            $table->text('description');
            $table->enum('size',['small','medium','large'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food');
    }
};
