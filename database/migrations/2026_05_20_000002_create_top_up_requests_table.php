<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('top_up_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('amount');
            $table->text('comment')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('top_up_requests');
    }
};
