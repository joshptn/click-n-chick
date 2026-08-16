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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            // Null until the registration OTP is confirmed. Paired with
            // account_status = 'pending_verification' during the blocking flow.
            $table->timestamp('phone_verified_at')->nullable();
            // Channel the user chose at registration ('sms' or 'email'). The
            // blocking flow gates on whichever this names; the other channel
            // stays unverified until they choose to verify it later.
            $table->string('verification_channel')->default('sms');
            // RA 10173 (Data Privacy Act) consent to store delivery address and
            // order history. A timestamp rather than a boolean: demonstrating
            // consent requires knowing when it was given, and null doubles as
            // "not yet consented" so withdrawal is just setting it back to null.
            $table->timestamp('privacy_consent_at')->nullable();
            $table->string('role')->default('user');
            $table->string('account_status')->default('active');
            $table->integer('loyalty_points')->default(0);
            $table->string('password');
            $table->string('phone_number_hash')->nullable()->unique();
            $table->string('avatar')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            // Fixed at enable-time and reused for every subsequent login
            // challenge - the user does not re-choose per login.
            $table->string('two_factor_channel')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
