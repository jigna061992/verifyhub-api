<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin wallet adjustments now record how the money was received and its bank reference (UTR / receipt no.)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_ledgers', function (Blueprint $table) {
            $table->string('payment_mode', 20)->nullable()->after('description');
            // Unique: the same bank reference can never be credited twice (many NULLs are allowed)
            $table->string('txn_reference', 40)->nullable()->unique()->after('payment_mode');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledgers', function (Blueprint $table) {
            $table->dropUnique(['txn_reference']);
            $table->dropColumn(['payment_mode', 'txn_reference']);
        });
    }
};
