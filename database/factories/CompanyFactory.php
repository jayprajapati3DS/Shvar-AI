<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'status' => CompanyStatus::Prospect->value,
            'name' => $this->faker->unique()->company(),
            'website' => 'https://'.$this->faker->domainName(),
            'country' => $this->faker->country(),
            'state' => $this->faker->state(),
            'city' => $this->faker->city(),
            'industry' => $this->faker->randomElement([
                'Medical Devices', 'Hospital', 'Medical Software', 'University', '3D Printing',
            ]),
            'company_type' => $this->faker->randomElement([
                'PSI Manufacturer', 'Implant Company', 'Research Institute', 'Distributor',
            ]),
            'description' => $this->faker->sentence(),
            'specialties' => 'Orthopedics',
            'products_services' => $this->faker->sentence(),
            'notes' => null,
        ];
    }
}
