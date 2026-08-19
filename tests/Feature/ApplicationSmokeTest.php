<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Product;
use App\Services\AI\LocalEndpointGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Every GET page must render, both empty and populated, and every HTTP request
 * the application makes must stay on this machine.
 *
 * Since Phase 2 the AI screens legitimately call Ollama on localhost, so the
 * privacy assertion is no longer "sends nothing" - it is "sends nothing to a
 * non-local host", which is the guarantee that actually matters.
 */
class ApplicationSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ollama is stubbed as unreachable by default: the AI pages then take
        // their real "not connected" path, deterministically and without needing
        // Ollama installed on the machine running the suite.
        Http::fake(fn () => Http::response('', 500));
    }

    /** @return list<array{string}> */
    public static function pageRoutes(): array
    {
        return [
            'dashboard' => ['dashboard'],
            'leads' => ['leads.index'],
            'companies' => ['companies.index'],
            'contacts' => ['contacts.index'],
            'products' => ['products.index'],
            'import' => ['import.create'],
            'email drafts' => ['email-drafts.index'],
            'follow-ups' => ['follow-ups.index'],
            'knowledge base' => ['knowledge-base.index'],
            'ai settings' => ['settings.ai.index'],
            'ai playground' => ['settings.ai.playground'],
            'ai logs' => ['settings.ai.logs'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pageRoutes')]
    public function test_every_page_renders_on_an_empty_database(string $name): void
    {
        $this->get(route($name))->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pageRoutes')]
    public function test_every_page_renders_with_data(string $name): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $lead = Lead::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);
        $lead->productMatches()->create(['product_id' => Product::factory()->create()->id]);

        $this->get(route($name))->assertOk();
    }

    public function test_every_detail_page_renders(): void
    {
        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $lead = Lead::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);
        $product = Product::factory()->create();
        $lead->productMatches()->create(['product_id' => $product->id]);

        $this->get(route('companies.show', $company))->assertOk();
        $this->get(route('contacts.show', $contact))->assertOk();
        $this->get(route('leads.show', $lead))->assertOk();
        $this->get(route('products.show', $product))->assertOk();
    }

    public function test_the_placeholder_pages_report_real_counts(): void
    {
        Lead::factory()->status(\App\Enums\LeadStatus::Qualified)->create();
        Lead::factory()->status(\App\Enums\LeadStatus::FollowUp)->create();
        Product::factory()->count(3)->create();
        Product::factory()->inactive()->create();

        $this->get(route('email-drafts.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Placeholders/EmailDrafts')
                ->where('context.leadsAwaitingOutreach', 1));

        $this->get(route('follow-ups.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Placeholders/FollowUps')
                ->where('context.leadsInFollowUp', 1));

        $this->get(route('knowledge-base.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Placeholders/KnowledgeBase')
                ->where('context.products', 4)
                ->where('context.activeProducts', 3));
    }

    public function test_settings_is_a_real_module_and_no_longer_a_placeholder(): void
    {
        // Phase 2 graduated Settings out of PlaceholderController.
        $this->get(route('settings.index'))->assertRedirect(route('settings.ai.index'));

        $this->get(route('settings.ai.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Ai')
                ->where('environment.appName', config('app.name'))
                ->where('environment.database', 'sqlite'));
    }

    public function test_browsing_the_crm_makes_no_http_request_at_all(): void
    {
        // The CRM pages have no reason to touch the network, AI or otherwise.
        Http::preventStrayRequests();

        $company = Company::factory()->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $lead = Lead::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);
        $product = Product::factory()->create();
        $lead->productMatches()->create(['product_id' => $product->id]);

        foreach ([
            'dashboard', 'leads.index', 'companies.index', 'contacts.index',
            'products.index', 'import.create',
            'email-drafts.index', 'follow-ups.index', 'knowledge-base.index',
        ] as $name) {
            $this->get(route($name))->assertOk();
        }

        $this->get(route('companies.show', $company))->assertOk();
        $this->get(route('contacts.show', $contact))->assertOk();
        $this->get(route('products.show', $product))->assertOk();

        // NOTE: leads.show is deliberately absent. Since Phase 3 it probes the
        // local model to decide whether the "Analyze Lead" button can be
        // enabled, so it legitimately makes one request - to localhost. That is
        // covered by test_every_request_the_ai_pages_make_stays_on_this_machine.
        Http::assertNothingSent();
    }

    public function test_every_request_the_ai_pages_make_stays_on_this_machine(): void
    {
        // The AI screens do make requests - to Ollama. This asserts that every
        // single one went to a local address, which is the privacy guarantee.
        $company = Company::factory()->create();
        $lead = Lead::factory()->create(['company_id' => $company->id]);

        $this->get(route('settings.ai.index'))->assertOk();
        $this->get(route('settings.ai.playground'))->assertOk();
        $this->post(route('settings.ai.test'));

        // Phase 3: the lead page probes the model, and analysis calls it.
        $this->get(route('leads.show', $lead))->assertOk();
        $this->post(route('leads.analyze', $lead));

        $guard = app(LocalEndpointGuard::class);
        $recorded = Http::recorded();

        // Sanity check: if nothing was recorded the assertion below is vacuous.
        $this->assertNotEmpty($recorded, 'Expected the AI pages to probe Ollama.');

        foreach ($recorded as [$request]) {
            $this->assertTrue(
                $guard->isLocal($request->url()),
                "Request to [{$request->url()}] left this machine.",
            );
        }
    }

    public function test_no_source_file_references_a_cloud_ai_provider(): void
    {
        // No cloud AI integration, in any phase. Asserted against the source tree
        // so adding one would be a deliberate, visible change.
        //
        // Ollama's own endpoint is deliberately NOT in this list - it is the whole
        // point of Phase 2. That AI traffic cannot leave the machine is covered by
        // test_every_request_the_ai_pages_make_stays_on_this_machine and by
        // AiSettingsTest's endpoint-guard cases.
        $forbidden = [
            'api.openai.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'api.cohere.ai',
            'api.mistral.ai',
        ];

        $sources = \Symfony\Component\Finder\Finder::create()
            ->in([app_path(), resource_path('js'), base_path('routes'), base_path('config')])
            ->files()
            ->name(['*.php', '*.ts', '*.vue']);

        foreach ($sources as $file) {
            $contents = $file->getContents();

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "{$file->getRelativePathname()} references {$needle}, which Phase 1 must not contain."
                );
            }
        }
    }

    public function test_the_local_ai_layer_is_bound_to_the_ollama_implementation(): void
    {
        // PHASE 2: the seam now has a real implementation behind it. The Phase 1
        // placeholder App\Contracts\Ai\AiProvider was superseded by
        // App\Services\AI\AIServiceInterface and removed.
        $this->assertFalse(
            interface_exists(\App\Contracts\Ai\AiProvider::class),
            'The Phase 1 AiProvider placeholder should have been replaced by AIServiceInterface.'
        );

        $this->assertTrue(interface_exists(\App\Services\AI\AIServiceInterface::class));

        $this->assertInstanceOf(
            \App\Services\AI\OllamaAIService::class,
            app(\App\Services\AI\AIServiceInterface::class),
        );
    }

    public function test_ai_product_matching_is_bound(): void
    {
        // PHASE 3: the Phase 1 ProductMatcher placeholder now has a real
        // implementation, resolved through the same provider as the AI service.
        $this->assertTrue(interface_exists(\App\Contracts\Ai\ProductMatcher::class));

        $this->assertInstanceOf(
            \App\Services\AI\Recommendation\AiProductMatcher::class,
            app(\App\Contracts\Ai\ProductMatcher::class),
        );
    }

    public function test_manual_product_selection_still_exists_alongside_ai(): void
    {
        // AI recommendation must never remove the ability to pick a product by
        // hand - the routes and the Manual provenance both remain.
        $paths = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertContains('leads/{lead}/products', $paths);
        $this->assertContains(
            \App\Enums\RecommendationType::Manual,
            \App\Enums\RecommendationType::cases(),
        );
    }

    public function test_the_ai_endpoint_is_local(): void
    {
        // The single most important privacy assertion in the suite.
        $this->assertTrue(
            app(\App\Services\AI\LocalEndpointGuard::class)->isLocal(config('ai.ollama.url')),
            'OLLAMA_URL must be a local address.'
        );
    }

    public function test_no_route_is_registered_for_a_phase_two_feature(): void
    {
        $paths = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        // Phase 4 features must not have crept in with Phase 3.
        foreach (['send-email', 'scrape', 'enrich', 'emails', 'gmail', 'outlook'] as $forbidden) {
            $this->assertNotContains($forbidden, $paths);
        }

        // And no email-generation request type is produced by anything.
        $this->assertFalse(\App\Enums\AiRequestType::EmailGeneration->isImplemented());
        $this->assertFalse(\App\Enums\AiRequestType::FollowUpGeneration->isImplemented());
    }
}
