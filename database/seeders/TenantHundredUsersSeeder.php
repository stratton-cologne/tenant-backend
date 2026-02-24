<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantHundredUsersSeeder extends Seeder
{
    public const PASSWORD = 'Password1234!';

    public function run(): void
    {
        $defaultRole = Role::query()->where('name', 'benutzer')->first();

        for ($i = 1; $i <= 100; $i++) {
            $firstName = 'User';
            $lastName = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $email = sprintf('user%03d@example.com', $i);

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password' => Hash::make(self::PASSWORD),
                    'mfa_type' => 'mail',
                    'mfa_secret' => null,
                    'mfa_app_setup_pending' => false,
                    'must_change_password' => false,
                    'temp_password_expires_at' => null,
                ]
            );

            if ($defaultRole !== null) {
                $user->roles()->syncWithoutDetaching([$defaultRole->id]);
            }
        }
    }
}

