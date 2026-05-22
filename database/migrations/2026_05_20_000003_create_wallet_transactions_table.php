<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('type', 16);
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->nullableMorphs('reference');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
