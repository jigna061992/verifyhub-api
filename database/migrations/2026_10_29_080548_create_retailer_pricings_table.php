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
        Schema::create('retailer_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('verification_services')->cascadeOnDelete();
            $table->decimal('custom_price', 10, 2);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['retailer_id', 'service_id']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retailer_pricings');
    }
};
