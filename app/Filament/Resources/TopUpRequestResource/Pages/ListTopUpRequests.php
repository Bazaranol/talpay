<?php

namespace App\Filament\Resources\TopUpRequestResource\Pages;

use App\Filament\Resources\TopUpRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListTopUpRequests extends ListRecords
{
    protected static string $resource = TopUpRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
