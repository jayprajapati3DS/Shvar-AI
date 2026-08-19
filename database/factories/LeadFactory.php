<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Company;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lead> */
class LeadFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),

            // A lead IS a person now, so a factory lead has a name and an
            // address by default - a nameless one is the exception, and tests
            // that want it say so.
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'job_title' => $this->faker->jobTitle(),
            'email' => $this->faker->unique()->safeEmail(),
            'lead_source' => $this->faker->randomElement(LeadSource::values()),
            'lead_status' => $this->faker->randomElement(LeadStatus::values()),
            'priority' => $this->faker->randomElement(Priority::values()),
            'assigned_to' => null,
            'notes' => null,
        ];
    }

    public function status(LeadStatus $status): self
    {
        return $this->state(['lead_status' => $status->value]);
    }

    public function priority(Priority $priority): self
    {
        return $this->state(['priority' => $priority->value]);
    }

    /** A lead with no person on it yet - a company you know of and nobody at. */
    public function unnamed(): self
    {
        return $this->state([
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'job_title' => null,
        ]);
    }

    /** Named, but with no way to write to them. */
    public function withoutEmail(): self
    {
        return $this->state(['email' => null]);
    }
}
