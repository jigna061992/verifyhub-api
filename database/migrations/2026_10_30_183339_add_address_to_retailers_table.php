<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retailers', function (Blueprint $table) {
            // Nullable so retailers created earlier (without an address) keep working
            $table->text('address')->nullable()->after('business_name');
        });
    }

    public function down(): void
    {
        Schema::table('retailers', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
