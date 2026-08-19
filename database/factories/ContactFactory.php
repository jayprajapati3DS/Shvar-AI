<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Contact> */
class ContactFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'job_title' => $this->faker->jobTitle(),
            'department' => $this->faker->randomElement(['R&D', 'Engineering', 'Procurement', 'Clinical']),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'linkedin_url' => 'https://linkedin.com/in/'.$this->faker->unique()->userName(),
            'country' => $this->faker->country(),
            'city' => $this->faker->city(),
            'notes' => null,
        ];
    }

    public function withoutCompany(): self
    {
        return $this->state(['company_id' => null]);
    }
}
