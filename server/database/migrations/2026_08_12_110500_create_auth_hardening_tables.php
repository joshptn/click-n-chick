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
            $table->string('device_fingerprint');
            $table->string('device_name')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
        Schema::dropIfExists('auth_events');
        Schema::dropIfExists('otp_codes');
    }
};
