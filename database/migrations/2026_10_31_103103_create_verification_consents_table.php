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
        Schema::create('verification_consents', function (Blueprint $table) {
        $table->id();    
        $table->foreignId('verification_id')->constrained('verifications')->cascadeOnDelete();
            $table->string('type', 20);                 // collection | signed_letter
            $table->string('purpose', 50);
            $table->string('text_version', 20);
            $table->char('text_hash', 64);              // SHA-256 of the exact text
            $table->text('text');                       // exact text, encrypted
            $table->string('channel', 30);              // retailer_assisted | esign
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('provider_ref')->nullable();
            $table->dateTime('accepted_at');

            $table->index(['verification_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_consents');
    }
};
