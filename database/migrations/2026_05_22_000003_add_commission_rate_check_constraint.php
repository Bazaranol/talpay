<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_commission_rate_bps_check CHECK (commission_rate_bps <= 10000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wallets DROP CONSTRAINT IF EXISTS wallets_commission_rate_bps_check');
    }
};
