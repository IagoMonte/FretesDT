<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([DominiosSeeder::class, AcessoSeeder::class, AdminSeeder::class]);

        if (! app()->environment('production')) {
            $this->call(DevSeeder::class);
        }
    }
}