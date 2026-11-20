<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decided 2026-11-20: the queue goes. An immediate order's daily number moves
 * into order_number ("007", given at payment, counted on the day it was
 * created); an advance order gets its own daily count in advance_order_number
 * (given at acceptance, counted on the day it was accepted). Numbers restart
 * every day and are not kept unique, so order_number loses its unique rule.
 *
 * Orders that already had a ticket keep it as their order_number; accepted
 * advance orders are numbered by the day and order they were accepted in.
 */
return new class extends Migration
{
    private const TIMEZONE = 'Asia/Manila';

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('advance_order_number', 10)->nullable()->after('order_number');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['order_number']);
        });

        // Whatever order_number held before (only demo data wrote it) is replaced.
        DB::table('orders')->whereNotNull('order_number')->update(['order_number' => null]);

        DB::table('orders')
            ->whereNotNull('queue_number')
            ->whereNull('scheduled_for')
            ->orderBy('id')
            ->select(['id', 'queue_number'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('orders')->where('id', $row->id)->update([
                        'order_number' => str_pad((string) $row->queue_number, 3, '0', STR_PAD_LEFT),
                    ]);
                }
            });

        $counts = [];

        DB::table('orders')
            ->whereNotNull('scheduled_for')
            ->whereNotNull('accepted_at')
            ->orderBy('accepted_at')
            ->orderBy('id')
            ->select(['id', 'accepted_at'])
            ->get()
            ->each(function ($row) use (&$counts) {
                $day = CarbonImmutable::parse($row->accepted_at, 'UTC')->setTimezone(self::TIMEZONE)->toDateString();
                $counts[$day] = ($counts[$day] ?? 0) + 1;

                DB::table('orders')->where('id', $row->id)->update([
                    'advance_order_number' => str_pad((string) $counts[$day], 3, '0', STR_PAD_LEFT),
                ]);
            });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['queue_date', 'queue_number']);
            $table->dropIndex(['status', 'queued_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['queue_number', 'queue_date', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('queue_number')->nullable();
            $table->date('queue_date')->nullable();
            $table->dateTime('queued_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(['queue_date', 'queue_number']);
            $table->index(['status', 'queued_at']);
            $table->dropColumn('advance_order_number');
        });

        DB::table('orders')->update(['order_number' => null]);

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('order_number');
        });
    }
};
