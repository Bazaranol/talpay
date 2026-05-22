<?php

namespace App\Providers;

use App\Models\RefundRequest;
use App\Models\TopUpRequest;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Policies\RefundRequestPolicy;
use App\Policies\TopUpRequestPolicy;
use App\Policies\WalletPolicy;
use App\Policies\WalletTransactionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Wallet::class, WalletPolicy::class);
        Gate::policy(TopUpRequest::class, TopUpRequestPolicy::class);
        Gate::policy(WalletTransaction::class, WalletTransactionPolicy::class);
        Gate::policy(RefundRequest::class, RefundRequestPolicy::class);
    }
}
