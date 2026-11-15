<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firebase Cloud Messaging registrations for the PWA and the native app
 * (PRD §9, C-09: an approved addition).
 *
 * Kept apart from known_devices on purpose: that table recognises devices for
 * account security, this one only says where a push can be delivered.
 *
 * No timestamps and no pruning job. Each token belongs to the sign-in that
 * registered it, and the foreign key deletes it with that sign-in: sign-out,
 * a device signed out from the Devices page, a password change, or the session
 * expiring and being pruned. A token FCM reports invalid is deleted by the
 * sender. So a token can never outlive the customer session it was given in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
            // One FCM token is one browser or app install, so it can only ever
            // point at one account: registering it again moves the row.
            $table->string('token', 1024)->unique();
            // 'web' (the PWA) or 'android' (the native app).
            $table->string('platform', 10);

            // Postgres does not index foreign keys by itself. Sends look tokens up
            // by user; the cascade looks them up by session.
            $table->index('user_id');
            $table->index('personal_access_token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
