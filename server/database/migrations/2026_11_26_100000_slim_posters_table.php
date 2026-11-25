<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posters (decided 2026-11-26): a poster is its image, its On Display switch
 * and an optional expiry date. The name, the uploader and the manual order are
 * retired; the homepage shows posters oldest first, by ID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posters', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'sort_order']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['poster_name', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::table('posters', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('poster_name')->default('');
            $table->unsignedInteger('sort_order')->default(0);

            $table->index(['is_active', 'sort_order']);
        });
    }
};
