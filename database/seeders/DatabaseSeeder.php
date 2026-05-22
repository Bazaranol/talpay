<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Администратор',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
            ]
        );

        $client = User::firstOrCreate(
            ['email' => 'client@example.com'],
            [
                'name' => 'Иван Клиентов',
                'password' => Hash::make('password'),
                'role' => UserRole::Client,
            ]
        );

        if ($client->wallet === null) {
            $wallet = Wallet::createForUser($client);
            $wallet->update(['commission_rate_bps' => 500]);
        }
    }
}
