<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ItEquipment\Tenant\Database\Seeders\ItEquipmentModuleSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantE2ESeeder::class,
            TicketModuleSeeder::class,
            ItEquipmentModuleSeeder::class,
        ]);
    }
}
