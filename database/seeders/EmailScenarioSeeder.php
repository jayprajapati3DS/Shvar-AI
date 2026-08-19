<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RecommendationStatus;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Makes the demo leads ready to write emails from.
 *
 *     php artisan db:seed --class=EmailScenarioSeeder
 *
 * An email can only be generated from an ACCEPTED product recommendation, so a
 * freshly seeded database has a Generate button that correctly refuses to do
 * anything. This accepts one sensible recommendation per demo lead, which is
 * the step a human would otherwise do by hand.
 *
 * It deliberately does NOT create any email drafts. Writing plausible emails
 * here and storing them as though a model produced them would put text in the
 * database that reads like AI output and is not - which is exactly the kind of
 * thing this application exists to avoid. To get real drafts:
 *
 *     php artisan email:scenarios
 *
 * The five archetypes from the Phase 4 brief are already covered by
 * SampleDataSeeder: a patient-specific implant manufacturer, an AI medical
 * imaging company, a medical university, an orthopedic implant company and a
 * general software company.
 */
class EmailScenarioSeeder extends Seeder
{
    /**
     * Which product to accept for each demo company.
     *
     * Chosen by hand rather than by running the matcher, so seeding works with
     * Ollama absent and always produces the same starting point.
     */
    private const CHOICES = [
        'Northfield Orthopedic Devices' => '3dsurgical Platform',
        'Alpine Maxillofacial Solutions' => '3dsurgical Platform',
        'Sundaram Institute of Medical Sciences' => 'Vision Anatomy VR',
        'Meridian AI Health' => 'MySegmenter',
        'Copper Basin Medical Printing' => 'MySegmenter',
    ];

    public function run(): void
    {
        if (Lead::query()->doesntExist()) {
            $this->call(SampleDataSeeder::class);
        }

        $products = Product::pluck('id', 'name');
        $accepted = 0;

        foreach (self::CHOICES as $companyName => $productName) {
            if (! $products->has($productName)) {
                $this->command?->warn("Skipping {$companyName}: no product named \"{$productName}\".");

                continue;
            }

            $lead = Lead::query()
                ->whereHas('company', fn ($q) => $q->where('name', $companyName))
                ->whereNotNull('contact_id')
                ->first();

            if ($lead === null) {
                continue;
            }

            LeadProductMatch::updateOrCreate(
                ['lead_id' => $lead->id, 'product_id' => $products[$productName]],
                [
                    'status' => RecommendationStatus::Accepted,
                    'reviewed_at' => now(),
                    'reason' => 'Accepted by the demo seeder so an email can be generated.',
                    'sales_angle' => 'Demo data - replace this with your own analysis.',
                ],
            );

            $accepted++;
        }

        if ($accepted === 0) {
            // Reached when the database holds real data rather than the demo
            // set. Doing nothing is correct here - this seeder must never
            // accept a recommendation on a lead you actually care about.
            $this->command?->warn(
                'No demo leads found, so nothing was changed. This seeder only touches the '
                .'companies created by SampleDataSeeder, never your own data.'
            );

            return;
        }

        $this->command?->info(
            "Accepted a product recommendation on {$accepted} demo lead(s). "
            .'Open one and use "Generate personalized email".'
        );
    }
}
