<?php

namespace Database\Seeders;

use App\Services\SystemSettingsService;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    /**
     * Seed baseline SISA system settings idempotently.
     */
    public function run(): void
    {
        app(SystemSettingsService::class)->seedDefaults();
    }
}
