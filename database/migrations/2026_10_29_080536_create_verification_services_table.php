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
        Schema::create('verification_services', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();// PAN, BANK, AADHAAR, GST, ESIGN
            $table->string('name');
            $table->string('provider')->nullable();
            $table->boolean('enabled')->default(true);
            $table->decimal('default_price', 10, 2);
            $table->decimal('provider_cost', 10, 2)->default(0);
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verification_services');
    }
};
