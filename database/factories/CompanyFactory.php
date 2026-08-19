<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Company> */
class CompanyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
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
