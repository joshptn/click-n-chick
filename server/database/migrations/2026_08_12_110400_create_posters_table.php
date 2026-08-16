<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Homepage image only: not orderable, carries no discount logic and has
        // no relationship to orders.
        Schema::create('posters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('poster_name');
            $table->string('image');
            // Taken down without deleting the row or waiting for expires_at.
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // Date it stops showing on the homepage.
            $table->date('expires_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posters');
    }
};
