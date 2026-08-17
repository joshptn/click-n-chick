<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            // Nullable: an OTP may precede account creation.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Which channel this code was delivered over: 'sms' or 'email'.
            // The row is otherwise channel-agnostic - one code table, one
            // verification path, transport chosen here.
            $table->string('channel')->default('sms');
            // Blind index over whichever identifier the channel addresses: the
            // canonical phone number for sms, the normalised address for email.
            // Named generically because the code table is not phone-only.
            // Lookup key, especially pre-registration where there is no user row.
            $table->string('identifier_hash')->nullable();
            $table->string('code_hash');
            // login, registration, verification
            $table->string('purpose');
            $table->string('ip_address', 45)->nullable();
            // Wrong guesses against this specific code. Capped so a code cannot be
            // brute-forced within its validity window even under the rate limits.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            // Every lookup is (identifier, purpose); the hash already differs per
            // channel, so channel does not need to join the key.
            $table->index(['identifier_hash', 'purpose']);
            $table->index('expires_at');
        });

        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();
            // Nullable: a failed login may carry an unrecognised identifier.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // login_success, login_failed, password_reset, two_fa_challenge
            $table->string('event_type');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index('ip_address');
        });

        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Derived server-side from the request (UA + an opaque client hint),
            // never accepted verbatim from the client. Scoped per user, so a
            // guessed fingerprint can only ever collide with the guesser's own
            // device row - it is an identity, not a credential.
            $table->string('device_fingerprint');
            $table->string('device_name')->nullable();
            // Coarse UA facts, kept for display only ("Chrome on Windows").
            $table->string('platform')->nullable();
            $table->string('last_ip_address', 45)->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_fingerprint']);
        });

        // The device<->session join (FR-01.13). A Sanctum token IS the session,
        // so the device link belongs on the token rather than in a second
        // session store that could disagree with it. Nullable because tokens
        // minted outside a request context have no device to attribute.
        //
        // Added here rather than in the Sanctum migration because that one runs
        // first and the referenced table would not exist yet.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('known_device_id')
                ->nullable()
                ->after('tokenable_type')
                ->constrained('known_devices')
                // Revoking a device deletes its rows; the tokens must go with
                // it, otherwise revocation would leave the session usable.
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['known_device_id']);
            $table->dropColumn('known_device_id');
        });

        Schema::dropIfExists('known_devices');
        Schema::dropIfExists('auth_events');
        Schema::dropIfExists('otp_codes');
    }
};
