<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->unsignedSmallInteger('commission_rate_bps')->default(0);
        });

        Schema::table('top_up_requests', function (Blueprint $table) {
            $table->bigInteger('commission_amount')->nullable();
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->bigInteger('commission_amount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('wallets', fn (Blueprint $t) => $t->dropColumn('commission_rate_bps'));
        Schema::table('top_up_requests', fn (Blueprint $t) => $t->dropColumn('commission_amount'));
        Schema::table('wallet_transactions', fn (Blueprint $t) => $t->dropColumn('commission_amount'));
    }
};
