<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('verification_services');
            $table->uuid('reference')->unique();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');

            // Only the masked value is stored (example: XXXXXX234F). The full PAN/account number is never saved.
            $table->string('input_masked');

            // Provider answer (name, status...). Stored encrypted, so use text.
            $table->text('result')->nullable();
            $table->string('provider_ref')->nullable();
            $table->string('failure_reason')->nullable();

            // Price charged to the retailer and our cost, copied at the time of the check
            $table->decimal('price', 10, 2);
            $table->decimal('provider_cost', 10, 2)->default(0);

            $table->timestamps();
            $table->index(['retailer_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
