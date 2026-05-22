<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('amount');
            $table->text('reason')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['wallet_transaction_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
