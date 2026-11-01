<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Complete KYC (the "case")
        Schema::create('kyc_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('verification_services');

            $table->enum('status', ['in_progress', 'completed', 'failed', 'cancelled', 'expired'])->default('in_progress');
            $table->string('current_step', 20)->default('aadhaar');

            // Customer details typed by the retailer. Stored encrypted, so text columns.
            $table->text('customer_name');
            $table->text('customer_mobile');
            $table->string('customer_mobile_last4', 4);

            // Bundle price (held in the wallet) and our cost, copied at the time of the case
            $table->decimal('price', 10, 2);
            $table->decimal('provider_cost', 10, 2)->default(0);

            $table->string('failure_reason')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['retailer_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        // Aadhaar, PAN, bank, e-Sign: one row each per case
        Schema::create('kyc_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('kyc_cases')->cascadeOnDelete();
            $table->string('step', 20);                       // aadhaar | pan | bank | esign
            $table->string('status', 20)->default('pending'); // pending | awaiting | processing | success | failed

            $table->unsignedTinyInteger('attempts')->default(0);   // failed tries
            $table->unsignedTinyInteger('otp_sends')->default(0);
            $table->dateTime('otp_sent_at')->nullable();

            $table->string('input_masked')->nullable();       // only masked values, never the full number
            $table->text('result')->nullable();               // provider answer, encrypted
            $table->text('meta')->nullable();                 // sign link, letter text, encrypted
            $table->string('provider_ref')->nullable();
            $table->string('failure_reason')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['case_id', 'step']);
            $table->index(['step', 'provider_ref']);
        });

        // Consent log: what the customer agreed to, when, and how. Rows are only added, never edited.
        Schema::create('kyc_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('kyc_cases')->cascadeOnDelete();
            $table->string('type', 20);                       // collection | signed_letter
            $table->string('purpose', 50);
            $table->string('text_version', 20);
            $table->char('text_hash', 64);                    // SHA-256 of the exact text shown/signed
            $table->text('text');                             // exact text, encrypted
            $table->string('channel', 30);                    // retailer_assisted | esign
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('provider_ref')->nullable();
            $table->dateTime('accepted_at');

            $table->index(['case_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_consents');
        Schema::dropIfExists('kyc_steps');
        Schema::dropIfExists('kyc_cases');
    }
};
