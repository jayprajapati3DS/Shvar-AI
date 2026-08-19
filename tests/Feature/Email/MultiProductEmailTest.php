<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\RecommendationStatus;
use App\Models\Company;
use App\Models\EmailGeneration;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Models\Product;
use App\Services\Email\EmailGenerator;
use App\Services\Email\EmailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Writing one email about more than one product.
 *
 * The first product is the PRIMARY: what the email is built around, what the
 * drafts list shows, what the filters match. The rest are woven in only where
 * the company data supports it.
 *
 * The interesting cases are the limits. An email that pitches everything in the
 * catalogue is worse than one that pitches nothing, so the cap, the ordering
 * and the de-duplication all matter more than the happy path.
 */
class MultiProductEmailTest extends TestCase
{
    use RefreshDatabase;

    private Lead $lead;

    /** @var array<string, LeadProductMatch> */
    private array $recommendations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create([
            'name' => 'Craniofax Implants',
            'description' => 'Develops patient-specific cranial implants.',
        ]);

        $this->lead = Lead::factory()->create([
            'company_id' => $company->id,
            'first_name' => 'Dana',
            'email' => 'dana@craniofax.example',
        ]);

        foreach ([
            'platform' => '3dsurgical Platform',
            'segmenter' => 'MySegmenter',
            'viewer' => 'Vision Anatomy VR',
            'extra' => 'Fourth Product',
        ] as $key => $name) {
            $product = Product::factory()->create([
                'name' => $name,
                'short_description' => "What {$name} does.",
                'active' => true,
            ]);

            $this->recommendations[$key] = LeadProductMatch::create([
                'lead_id' => $this->lead->id,
                'product_id' => $product->id,
                'status' => RecommendationStatus::Accepted,
                'reason' => "Why {$name} fits.",
                'sales_angle' => "The angle for {$name}.",
            ]);
        }

        app(EmailSettings::class)->save([
            'sender_name' => 'Jay Prajapati',
            'sender_company' => '3D Surgical',
        ]);
    }

    private function fakeAi(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => json_encode([
                    'variants' => [[
                        'style' => 'professional_direct',
                        'subject' => 'Case review workflow',
                        'body' => "Hi Dana,\n\nA note about surgeon review.\n\n"
                            ."Would you be open to a short call?\n\nBest regards,",
                    ]],
                    'claims_used' => ['The platform supports surgeon review.'],
                    'personalization_points' => ['They develop cranial implants.'],
                ]),
                'done' => true,
            ]),
        ]);
    }

    private function generate(array $keys): EmailGeneration
    {
        $this->fakeAi();

        return app(EmailGenerator::class)->generate(
            $this->lead,
            array_map(fn (string $k) => $this->recommendations[$k], $keys),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Persistence */
    /* ------------------------------------------------------------------ */

    public function test_every_selected_product_is_recorded(): void
    {
        $generation = $this->generate(['platform', 'segmenter']);

        $this->assertCount(2, $generation->recommendations);
        $this->assertSame(
            ['3dsurgical Platform', 'MySegmenter'],
            $generation->productNames(),
        );
    }

    public function test_the_first_selected_product_becomes_the_primary(): void
    {
        $generation = $this->generate(['segmenter', 'platform']);

        // The generation's own product_id is the primary, so the drafts list and
        // the filters keep showing one answer to "what is this about".
        $this->assertSame(
            $this->recommendations['segmenter']->product_id,
            $generation->product_id,
        );

        $primary = $generation->recommendations->firstWhere('pivot.is_primary', true);

        $this->assertSame($this->recommendations['segmenter']->id, $primary->id);
    }

    public function test_the_drafts_carry_the_primary_product(): void
    {
        $generation = $this->generate(['platform', 'segmenter', 'viewer']);

        foreach ($generation->drafts as $draft) {
            $this->assertSame($this->recommendations['platform']->product_id, $draft->product_id);
        }
    }

    public function test_the_chosen_order_is_preserved(): void
    {
        $generation = $this->generate(['viewer', 'platform', 'segmenter']);

        $this->assertSame(
            ['Vision Anatomy VR', '3dsurgical Platform', 'MySegmenter'],
            $generation->productNames(),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Limits */
    /* ------------------------------------------------------------------ */

    public function test_more_than_three_products_are_capped(): void
    {
        $generation = $this->generate(['platform', 'segmenter', 'viewer', 'extra']);

        // Past three the email stops being about anything and becomes a list of
        // what we sell.
        $this->assertCount(EmailGenerator::MAX_PRODUCTS, $generation->recommendations);
        $this->assertNotContains('Fourth Product', $generation->productNames());
    }

    public function test_the_same_product_twice_is_collapsed(): void
    {
        $generation = $this->generate(['platform', 'platform', 'segmenter']);

        $this->assertCount(2, $generation->recommendations);
    }

    public function test_generating_with_no_recommendation_is_refused(): void
    {
        $this->fakeAi();

        $this->expectException(\InvalidArgumentException::class);

        app(EmailGenerator::class)->generate($this->lead, []);
    }

    /* ------------------------------------------------------------------ */
    /* The prompt */
    /* ------------------------------------------------------------------ */

    public function test_the_prompt_names_every_product_and_says_which_leads(): void
    {
        $this->generate(['platform', 'segmenter']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $prompt = $request->data()['prompt'] ?? '';

            return str_contains($prompt, 'PRODUCT 1 OF 2 - THE PRIMARY, LEAD WITH THIS')
                && str_contains($prompt, 'PRODUCT 2 OF 2 - SECONDARY')
                && str_contains($prompt, '3dsurgical Platform')
                && str_contains($prompt, 'MySegmenter');
        });
    }

    public function test_the_prompt_warns_against_writing_a_catalogue(): void
    {
        $this->generate(['platform', 'segmenter']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $prompt = $request->data()['prompt'] ?? '';

            return str_contains($prompt, 'HOW TO HANDLE THESE 2 PRODUCTS')
                && str_contains($prompt, 'Do not write a bulleted product list')
                && str_contains($prompt, 'at most one sentence');
        });
    }

    public function test_a_single_product_prompt_is_unchanged(): void
    {
        $this->generate(['platform']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/generate')) {
                return true;
            }

            $prompt = $request->data()['prompt'] ?? '';

            // No multi-product guidance when there is only one product - a model
            // told "lead with the primary" when there is nothing else starts
            // explaining which product it chose.
            return str_contains($prompt, 'THE PRODUCT I AM WRITING ABOUT')
                && ! str_contains($prompt, 'HOW TO HANDLE THESE');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Claim checking across products */
    /* ------------------------------------------------------------------ */

    public function test_a_claim_about_the_secondary_product_is_not_flagged(): void
    {
        Http::fake([
            '*/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b']]]),
            '*/api/generate' => Http::response([
                'model' => 'qwen3:8b',
                'response' => json_encode([
                    'variants' => [[
                        'style' => 'professional_direct',
                        'subject' => 'Segmentation and case review',
                        'body' => "Hi Dana,\n\nA note.\n\nWould you be open to a call?\n\nBest regards,",
                    ]],
                    // About the SECOND product. Checking only against the primary
                    // would flag a perfectly good sentence.
                    'claims_used' => ['What MySegmenter does.'],
                    'personalization_points' => [],
                ]),
                'done' => true,
            ]),
        ]);

        $generation = app(EmailGenerator::class)->generate($this->lead, [
            $this->recommendations['platform'],
            $this->recommendations['segmenter'],
        ]);

        $this->assertStringNotContainsString(
            'does not appear there',
            implode(' ', $generation->warnings ?? []),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Over HTTP */
    /* ------------------------------------------------------------------ */

    public function test_secondary_products_can_be_chosen_from_the_lead_page(): void
    {
        $this->fakeAi();

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendations['platform']->id,
            'secondary_recommendation_ids' => [
                $this->recommendations['segmenter']->id,
                $this->recommendations['viewer']->id,
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $generation = EmailGeneration::latest('id')->first();

        $this->assertCount(3, $generation->recommendations);
        $this->assertStringContainsString('3 products', (string) session('success'));
    }

    public function test_too_many_secondary_products_are_rejected_with_an_explanation(): void
    {
        $this->fakeAi();

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendations['platform']->id,
            'secondary_recommendation_ids' => [
                $this->recommendations['segmenter']->id,
                $this->recommendations['viewer']->id,
                $this->recommendations['extra']->id,
            ],
        ])->assertSessionHasErrors('secondary_recommendation_ids');

        $this->assertDatabaseCount('email_generations', 0);
    }

    public function test_a_secondary_that_is_not_accepted_is_refused(): void
    {
        $this->fakeAi();
        $this->recommendations['segmenter']->update(['status' => RecommendationStatus::Suggested]);

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendations['platform']->id,
            'secondary_recommendation_ids' => [$this->recommendations['segmenter']->id],
        ])->assertRedirect();

        // Every product in the email follows the direction the user approved,
        // not just the one it leads with.
        $this->assertStringContainsString('Accept that product recommendation first', (string) session('error'));
        $this->assertDatabaseCount('email_generations', 0);
    }

    public function test_a_secondary_belonging_to_another_lead_is_refused(): void
    {
        $this->fakeAi();

        $otherLead = Lead::factory()->create();
        $foreign = LeadProductMatch::create([
            'lead_id' => $otherLead->id,
            'product_id' => $this->recommendations['segmenter']->product_id,
            'status' => RecommendationStatus::Accepted,
        ]);

        $this->post(route('leads.emails.generate', $this->lead), [
            'recommendation_id' => $this->recommendations['platform']->id,
            'secondary_recommendation_ids' => [$foreign->id],
        ])->assertRedirect();

        $this->assertStringContainsString('does not belong to this lead', (string) session('error'));
        $this->assertDatabaseCount('email_generations', 0);
    }

    public function test_deleting_a_generation_removes_its_product_links(): void
    {
        $generation = $this->generate(['platform', 'segmenter']);

        $this->assertDatabaseCount('email_generation_recommendations', 2);

        $generation->delete();

        $this->assertDatabaseCount('email_generation_recommendations', 0);
    }
}
