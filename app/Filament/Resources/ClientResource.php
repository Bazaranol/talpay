<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers\WalletTransactionsRelationManager;
use App\Models\User;
use App\Models\Wallet;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Клиенты';

    protected static ?string $modelLabel = 'Клиент';

    protected static ?string $pluralModelLabel = 'Клиенты';

    protected static ?string $slug = 'clients';

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', UserRole::Client);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Имя')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('wallet.balance')
                    ->label('Баланс')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? Wallet::formatMinor($state) : '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('wallet.commission_rate_bps')
                    ->label('Комиссия')
                    ->formatStateUsing(fn (?int $state) => $state !== null
                        ? number_format($state / 100, 2, ',', '').'%'
                        : '0%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Зарегистрирован')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (User $record) => Pages\ViewClient::getUrl(['record' => $record]))
            ->bulkActions([]);
    }

    /** @return array<int, class-string<\Filament\Resources\RelationManagers\RelationManager>> */
    public static function getRelationManagers(): array
    {
        return [
            WalletTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'view' => Pages\ViewClient::route('/{record}'),
        ];
    }
}
