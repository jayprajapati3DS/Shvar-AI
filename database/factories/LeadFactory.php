<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Lead> */
class LeadFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'contact_id' => null,
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
}
