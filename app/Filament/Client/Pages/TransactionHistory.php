<?php

namespace App\Filament\Client\Pages;

use App\Enums\TransactionType;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TransactionHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'История операций';

    protected static ?string $title = 'История операций';

    protected static string $view = 'filament.client.pages.transaction-history';

    protected static ?string $slug = 'history';

    public function table(Table $table): Table
    {
        $walletId = auth()->user()?->wallet?->id;

        return $table
            ->query(
                $walletId
                    ? WalletTransaction::query()->forWallet($walletId)
                    : WalletTransaction::query()->whereRaw('1 = 0')
            )
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (TransactionType $state) => match ($state) {
                        TransactionType::TopUp => 'Пополнение',
                        TransactionType::Debit => 'Списание',
                        TransactionType::Refund => 'Возврат',
                    })
                    ->color(fn (TransactionType $state) => match ($state) {
                        TransactionType::TopUp => 'success',
                        TransactionType::Debit => 'danger',
                        TransactionType::Refund => 'warning',
                    }),

                Tables\Columns\TextColumn::make('refund_kind')
                    ->label('Вид возврата')
                    ->state(function (WalletTransaction $record): ?string {
                        if ($record->type !== TransactionType::Refund) {
                            return null;
                        }
                        $debit = $record->reference;

                        return $debit && $record->amount === $debit->amount ? 'Полный' : 'Частичный';
                    })
                    ->badge()
                    ->color(fn (?string $state) => $state === 'Полный' ? 'success' : 'gray')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сумма')
                    ->formatStateUsing(function (int $state, WalletTransaction $record): string {
                        $main = Wallet::formatMinor($state, $record->currency);
                        $sign = $record->type === TransactionType::Debit ? '−' : '+';
                        $line = "{$sign}{$main}";
                        if ($record->type === TransactionType::TopUp && $record->commission_amount > 0) {
                            $fee = Wallet::formatMinor($record->commission_amount, $record->currency);
                            $line .= " (комиссия {$fee})";
                        }

                        return $line;
                    })
                    ->color(fn (WalletTransaction $record): string => match ($record->type) {
                        TransactionType::Debit => 'danger',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference_id')
                    ->label('Основание')
                    ->formatStateUsing(function (?int $state, WalletTransaction $record): string {
                        return match ($record->type) {
                            TransactionType::TopUp =>
                                $state !== null
                                    ? "Заявка №{$state} от ".$record->reference?->created_at->format('d.m.Y H:i:s')
                                    : '—',
                            TransactionType::Debit => 'Прямое списание',
                            TransactionType::Refund =>
                                $state !== null
                                    ? "Возврат списания №{$state} от ".$record->reference?->created_at->format('d.m.Y H:i:s')
                                    : '—',
                        };
                    }),

                Tables\Columns\TextColumn::make('description')
                    ->label('Описание')
                    ->limit(50)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Баланс после')
                    ->formatStateUsing(
                        fn (int $state, WalletTransaction $record) => Wallet::formatMinor($state, $record->currency)
                    ),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип операции')
                    ->options([
                        TransactionType::TopUp->value => 'Пополнение',
                        TransactionType::Debit->value => 'Списание',
                        TransactionType::Refund->value => 'Возврат',
                    ]),

                Filter::make('created_at')
                    ->label('Период')
                    ->form([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('to')->label('По'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                            ->when($data['to'], fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
                    }),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }
}
