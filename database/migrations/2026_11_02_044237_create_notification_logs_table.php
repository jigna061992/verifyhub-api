<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);            // topup_approved, topup_rejected, low_balance, wallet_credited, wallet_debited
            $table->string('title');
            $table->string('message', 500);
            $table->json('data')->nullable();      // link, amount, ids (never passwords or identity numbers)
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);       // unread count
            $table->index(['user_id', 'created_at']);    // list, newest first
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
