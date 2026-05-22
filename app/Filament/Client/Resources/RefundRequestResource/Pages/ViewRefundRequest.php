<?php

namespace App\Filament\Client\Resources\RefundRequestResource\Pages;

use App\Enums\RefundRequestStatus;
use App\Filament\Client\Resources\RefundRequestResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewRefundRequest extends ViewRecord
{
    protected static string $resource = RefundRequestResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('id')->label('#'),
            TextEntry::make('debit.id')
                ->label('Списание')
                ->formatStateUsing(fn (?int $state) => $state !== null ? "№{$state}" : '—'),
            TextEntry::make('amount')
                ->label('Сумма')
                ->formatStateUsing(fn (int $state) => number_format($state / 100, 2, ',', ' ').' ₽'),
            TextEntry::make('status')
                ->label('Статус')
                ->badge()
                ->formatStateUsing(fn (RefundRequestStatus $state) => match ($state) {
                    RefundRequestStatus::Pending => 'Ожидает',
                    RefundRequestStatus::Confirmed => 'Подтверждена',
                    RefundRequestStatus::Rejected => 'Отклонена',
                })
                ->color(fn (RefundRequestStatus $state) => match ($state) {
                    RefundRequestStatus::Pending => 'warning',
                    RefundRequestStatus::Confirmed => 'success',
                    RefundRequestStatus::Rejected => 'danger',
                }),
            TextEntry::make('reason')->label('Причина')->placeholder('—'),
            TextEntry::make('rejection_reason')->label('Причина отклонения')->placeholder('—'),
            TextEntry::make('created_at')->label('Создана')->dateTime('d.m.Y H:i:s'),
        ]);
    }
}
