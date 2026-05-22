<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use App\Models\User;
use App\Models\Wallet;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * @property User $record
 */
class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Профиль')->schema([
                TextEntry::make('id')->label('#'),
                TextEntry::make('name')->label('Имя'),
                TextEntry::make('email')->label('Email'),
                TextEntry::make('created_at')->label('Зарегистрирован')->dateTime('d.m.Y H:i:s'),
            ])->columns(2),

            Section::make('Кошелёк')->schema([
                TextEntry::make('wallet.balance')
                    ->label('Текущий баланс')
                    ->formatStateUsing(fn (?int $state) => $state !== null ? Wallet::formatMinor($state) : '—'),

                TextEntry::make('wallet.currency')
                    ->label('Валюта'),

                TextEntry::make('wallet.commission_rate_bps')
                    ->label('Комиссия')
                    ->formatStateUsing(fn (?int $state) => $state !== null
                        ? number_format($state / 100, 2, '.', '').'%'
                        : '0%'),
            ])->columns(2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setCommission')
                ->label('Изменить комиссию')
                ->icon('heroicon-o-percent-badge')
                ->fillForm(function (): array {
                    $wallet = $this->record->wallet;
                    $bps = $wallet !== null ? $wallet->commission_rate_bps : 0;

                    return ['commission_percent' => number_format($bps / 100, 2, '.', '')];
                })
                ->form([
                    TextInput::make('commission_percent')
                        ->label('Комиссия %')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->suffix('%')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $wallet = $this->record->wallet;
                    if (! $wallet instanceof Wallet) {
                        return;
                    }
                    $wallet->update([
                        'commission_rate_bps' => (int) round((float) $data['commission_percent'] * 100),
                    ]);
                    Notification::make()->success()->title('Комиссия обновлена')->send();
                }),
        ];
    }
}
