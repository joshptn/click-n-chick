<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account-level discount eligibility (senior citizen / PWD).
 *
 * This row answers one question: "is this person entitled to a discount?" It
 * is settled once, by a Store Agent reviewing a submitted ID, and has nothing
 * to do with any particular order.
 *
 * It used to hang off orders.order_id and mix in per-order money
 * (vat_exempt_amount), which made one row mean two different things depending
 * on which columns were filled. Deciding entitlement and computing what comes
 * off a given basket are separate concerns that happen months apart, so the
 * money side is not stored here at all: the calculation runs at checkout,
 * against this entitlement, and lands in orders.discount_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            // Not nullable: entitlement is a property of an account, and a
            // guest has none. Guest discounts, if they ever happen, are an
            // at-the-counter decision rather than a stored claim.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // senior, pwd
            $table->string('discount_type');
            // The statutory rate this entitlement carries (20% for both today).
            // Stored rather than hardcoded so a rate change is data, not a deploy.
            $table->decimal('discount_percentage', 5, 2)->default(0);

            // Both senior and PWD entitlements are VAT-exempt by law. The peso
            // amount exempted is per-basket and is NOT stored here.
            $table->boolean('vat_exempt')->default(true);

            // Cloudinary URL of the submitted ID. Never public - served only to
            // the claimant and to reviewing staff.
            $table->string('id_image');
            $table->string('discount_status')->default('pending');

            // Shown back to the customer so a rejection is actionable rather
            // than a dead end they cannot recover from.
            $table->text('rejection_reason')->nullable();

            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            // The agent review queue: pending first, oldest first.
            $table->index(['discount_status', 'created_at']);
            // "Does this account have a live claim?" - asked on every profile load.
            $table->index(['user_id', 'discount_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
