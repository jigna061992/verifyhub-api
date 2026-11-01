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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('available_balance', 12, 2)->default(0);
            $table->decimal('reserved_balance', 12, 2)->default(0);
            $table->unsignedBigInteger('version')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
