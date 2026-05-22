<?php

namespace App\Filament\Resources;

use App\Enums\TransactionType;
use App\Filament\Resources\WalletTransactionResource\Pages;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Операции';

    protected static ?string $modelLabel = 'Операция';

    protected static ?string $pluralModelLabel = 'Операции';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('wallet.user.name')
                    ->label('Клиент')
                    ->description(fn (WalletTransaction $record) => $record->wallet->user->email)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'wallet.user',
                        fn (Builder $q) => $q
                            ->where('name', 'ilike', "%{$search}%")
                            ->orWhere('email', 'ilike', "%{$search}%")
                    ))
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
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_by')
                    ->label('Оператор')
                    ->formatStateUsing(fn () => 'Администратор'),

                Tables\Columns\TextColumn::make('balance_after')
                    ->label('Баланс после')
                    ->formatStateUsing(fn (int $state) => Wallet::formatMinor($state))
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options([
                        TransactionType::TopUp->value => 'Пополнение',
                        TransactionType::Debit->value => 'Списание',
                        TransactionType::Refund->value => 'Возврат',
                    ]),

                Filter::make('client')
                    ->label('Клиент')
                    ->form([
                        TextInput::make('search')->label('Имя или email'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['search'] ?? null,
                            fn ($q, $v) => $q->whereHas(
                                'wallet.user',
                                fn ($q) => $q
                                    ->where('email', 'like', "%{$v}%")
                                    ->orWhere('name', 'like', "%{$v}%")
                            )
                        );
                    }),

                Filter::make('amount_range')
                    ->label('Сумма (₽)')
                    ->form([
                        TextInput::make('from')->numeric()->label('От'),
                        TextInput::make('to')->numeric()->label('До'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('amount', '>=', (int) round($v * 100)))
                            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('amount', '<=', (int) round($v * 100)));
                    }),

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
            ->recordUrl(fn (WalletTransaction $record) => Pages\ViewWalletTransaction::getUrl(['record' => $record]))
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWalletTransactions::route('/'),
            'view' => Pages\ViewWalletTransaction::route('/{record}'),
        ];
    }
}
