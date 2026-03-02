<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ItEquipment\Tenant\Database\Seeders\ItEquipmentModuleSeeder;
use Modules\Tickets\Tenant\Database\Seeders\TicketsModuleSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TenantE2ESeeder::class,
            TicketsModuleSeeder::class,
            ItEquipmentModuleSeeder::class,
        ]);
    }
}
