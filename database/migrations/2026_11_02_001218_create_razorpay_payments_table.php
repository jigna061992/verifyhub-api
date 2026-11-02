<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Razorpay order started by a retailer (online wallet top-up)
        Schema::create('razorpay_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->string('order_id')->unique();                 // order_xxx, created on our server
            $table->string('payment_id')->nullable()->unique();   // pay_xxx, known after the customer pays
            $table->decimal('amount', 12, 2);                     // rupees
            $table->unsignedBigInteger('amount_paise');           // what was sent to Razorpay
            $table->char('currency', 3)->default('INR');
            $table->string('mode', 10);                           // test | live (from the key)
            $table->string('status', 20)->default('created');     // created | paid
            $table->string('last_error')->nullable();             // last payment failure shown by Razorpay
            $table->string('settled_via', 20)->nullable();        // checkout | webhook
            $table->foreignId('topup_request_id')->nullable()->constrained('wallet_topup_requests')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['retailer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('razorpay_payments');
    }
};
