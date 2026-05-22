<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use App\Enums\TransactionType;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class WalletTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'walletTransactions';

    protected static ?string $title = 'Последние транзакции';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
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
                    }),

                Tables\Columns\TextColumn::make('description')
                    ->label('Описание')
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Баланс после')
                    ->formatStateUsing(fn (int $state) => Wallet::formatMinor($state)),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginationPageOptions([10, 25])
            ->defaultPaginationPageOption(10)
            ->bulkActions([]);
    }
}
