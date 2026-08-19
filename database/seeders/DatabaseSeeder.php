<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Default seed: the product portfolio only.
 *
 * Companies / contacts / leads are deliberately NOT seeded here - that data is
 * yours and should come from real entry or CSV import, not fixtures. For a
 * populated demo database run the sample seeder explicitly:
 *
 *     php artisan db:seed --class=SampleDataSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ProductSeeder::class,
        ]);
    }
}
