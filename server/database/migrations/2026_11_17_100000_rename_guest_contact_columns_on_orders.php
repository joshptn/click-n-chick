<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every order now carries its own contact name and number (BR-21b, FR-02.9d,
 * decided 2026-11-17), not only a guest's. The guest columns already held
 * exactly that, so they are renamed rather than added to: no new columns.
 *
 * Rows from before keep what they had. An account order with nothing here is
 * read back off its account (Order::contactName / contactPhone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('guest_name', 'contact_name');
            $table->renameColumn('guest_phone', 'contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('contact_name', 'guest_name');
            $table->renameColumn('contact_phone', 'guest_phone');
        });
    }
};
