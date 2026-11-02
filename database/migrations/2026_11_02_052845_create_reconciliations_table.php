<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            // One reconciliation per verification. The unique key also stops a double row if the same check is held twice.
            $table->foreignId('verification_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('verification_services');
            $table->decimal('amount', 12, 2);                       // the amount that is on hold in the wallet
            $table->string('status', 12)->default('open');          // open | resolved
            $table->string('reason', 255);                          // why it was held (no provider secrets)
            $table->string('outcome', 12)->nullable();              // success (charged) | failed (money returned)
            $table->string('note', 255)->nullable();                // admin note, also written to the audit log
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('retailer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
