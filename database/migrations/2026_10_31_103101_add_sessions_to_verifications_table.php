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
        Schema::table('verifications', function (Blueprint $table) {
            $table->string('stage', 20)->nullable()->after('status');
    $table->text('meta')->nullable()->after('result');
    $table->unsignedTinyInteger('attempts')->default(0)->after('provider_ref');
    $table->unsignedTinyInteger('otp_sends')->default(0)->after('attempts');
    $table->dateTime('otp_sent_at')->nullable()->after('otp_sends');
    $table->dateTime('expires_at')->nullable()->after('otp_sent_at');
    $table->index(['status', 'expires_at']);
    $table->index('provider_ref');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
             $table->dropIndex(['status', 'expires_at']);
    $table->dropIndex(['provider_ref']);
    $table->dropColumn(['stage', 'meta', 'attempts', 'otp_sends', 'otp_sent_at', 'expires_at']);
        });
    }
};
