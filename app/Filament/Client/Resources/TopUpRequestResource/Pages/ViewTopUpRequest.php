<?php

namespace App\Filament\Client\Resources\TopUpRequestResource\Pages;

use App\Enums\TopUpRequestStatus;
use App\Filament\Client\Resources\TopUpRequestResource;
use App\Models\Wallet;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewTopUpRequest extends ViewRecord
{
    protected static string $resource = TopUpRequestResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('id')->label('#'),
            TextEntry::make('amount')
                ->label('Сумма заявки')
                ->formatStateUsing(fn (int $state) => Wallet::formatMinor($state)),
            TextEntry::make('commission_amount')
                ->label('Комиссия')
                ->formatStateUsing(fn (?int $state) => $state !== null ? Wallet::formatMinor($state) : '—')
                ->visible(fn (\App\Models\TopUpRequest $record) => $record->status === TopUpRequestStatus::Confirmed),
            TextEntry::make('net_amount')
                ->label('К зачислению')
                ->state(fn (\App\Models\TopUpRequest $record): ?int => $record->commission_amount !== null
                    ? $record->amount - $record->commission_amount
                    : null)
                ->formatStateUsing(fn (?int $state) => $state !== null ? Wallet::formatMinor($state) : '—')
                ->visible(fn (\App\Models\TopUpRequest $record) => $record->status === TopUpRequestStatus::Confirmed),
            TextEntry::make('status')
                ->label('Статус')
                ->badge()
                ->formatStateUsing(fn (TopUpRequestStatus $state) => match ($state) {
                    TopUpRequestStatus::Pending => 'Ожидает',
                    TopUpRequestStatus::Confirmed => 'Подтверждена',
                    TopUpRequestStatus::Rejected => 'Отклонена',
                })
                ->color(fn (TopUpRequestStatus $state) => match ($state) {
                    TopUpRequestStatus::Pending => 'warning',
                    TopUpRequestStatus::Confirmed => 'success',
                    TopUpRequestStatus::Rejected => 'danger',
                }),
            TextEntry::make('comment')->label('Комментарий')->placeholder('—'),
            TextEntry::make('created_at')->label('Создана')->dateTime('d.m.Y H:i:s'),
        ]);
    }
}
