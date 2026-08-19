<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(3, true),
            'category' => $this->faker->randomElement([
                'Medical Software / Surgical Planning',
                'Medical Image Segmentation',
                'Professional Services',
                'Medical Education',
            ]),
            'short_description' => $this->faker->sentence(),
            'detailed_description' => $this->faker->paragraph(),
            // Newline-separated, matching how the seeder and UI store list fields.
            'target_customer' => "Hospitals\nMedical device companies",
            'target_specialty' => "Orthopedics\nMaxillofacial surgery",
            'key_features' => "Feature one\nFeature two",
            'value_proposition' => $this->faker->sentence(),
            'sales_notes' => null,
            'active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['active' => false]);
    }
}
