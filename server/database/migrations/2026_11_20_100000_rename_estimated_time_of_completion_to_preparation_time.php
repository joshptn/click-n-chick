<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decided 2026-11-20: the advance order's preparation lead time, in minutes,
 * entered by the Store Agent when accepting a request. Nothing wrote the old
 * estimate any more (its endpoint had no screen), so the column is renamed and
 * reused rather than a new one added. Whatever it held is meaningless now and
 * is cleared first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->whereNotNull('estimated_time_of_completion')->update(['estimated_time_of_completion' => null]);

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('estimated_time_of_completion', 'preparation_time');
        });

        // Postgres will not cast varchar to a number without being told how;
        // every value is already null.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders ALTER COLUMN preparation_time TYPE smallint USING NULL');

            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('preparation_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders ALTER COLUMN preparation_time TYPE varchar(255) USING preparation_time::varchar');
        } else {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('preparation_time')->nullable()->change();
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('preparation_time', 'estimated_time_of_completion');
        });
    }
};
