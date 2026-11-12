<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $clashes = DB::table('users')
            ->selectRaw('LOWER(email) AS address, COUNT(*) AS total')
            ->whereNotNull('email')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('address');

        if ($clashes->isNotEmpty()) {
            throw new RuntimeException(
                'These email addresses exist more than once with different capitalisation and must be merged first: '
                    .$clashes->implode(', ')
            );
        }

        DB::table('users')->whereRaw('email <> LOWER(email)')->update(['email' => DB::raw('LOWER(email)')]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });
    }
};
