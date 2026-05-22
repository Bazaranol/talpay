<?php

namespace App\Filament\Client\Resources\TopUpRequestResource\Pages;

use App\Filament\Client\Resources\TopUpRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListTopUpRequests extends ListRecords
{
    protected static string $resource = TopUpRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
