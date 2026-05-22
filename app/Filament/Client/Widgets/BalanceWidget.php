<?php

namespace App\Filament\Client\Widgets;

use App\Models\Wallet;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BalanceWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $wallet = auth()->user()?->wallet;

        $balance = $wallet
            ? $wallet->balance_as_money->formatTo('ru_RU')
            : '—';

        return [
            Stat::make('Текущий баланс', $balance)
                ->description(Wallet::DEFAULT_CURRENCY)
                ->color('success'),
        ];
    }
}
