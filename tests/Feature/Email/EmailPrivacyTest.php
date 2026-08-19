<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Services\AI\LocalEndpointGuard;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\LocalTestEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Throwable;

/**
 * The Phase 4 privacy boundary, asserted against the source tree.
 *
 * These are the tests that would fail if a future change quietly introduced a
 * cloud AI provider or a real mail transport. They read files rather than
 * exercising behaviour, on purpose: behaviour tests prove what the code does
 * today, and these prove what it is not allowed to become.
 */
class EmailPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** Source directories that must stay free of external integrations. */
    private const SCANNED = ['app', 'config', 'routes'];

    /**
     * Every .php file under the scanned directories.
     *
     * @return array<string, string> path => contents
     */
    private function sources(): array
    {
        $files = [];

        foreach (self::SCANNED as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir))
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }

    /* ------------------------------------------------------------------ */
    /* No cloud AI */
    /* ------------------------------------------------------------------ */

    public function test_no_cloud_ai_provider_appears_anywhere_in_the_source(): void
    {
        // Deliberately checks for the API HOSTS rather than the brand names -
        // the names appear legitimately in comments explaining what this
        // application does not do, but a hostname could only ever be a call.
        $hosts = [
            'api.openai.com',
            'api.anthropic.com',
            'generativelanguage.googleapis.com',
            'openai.azure.com',
            'api.cohere.ai',
            'api.mistral.ai',
            'api.together.xyz',
        ];

        foreach ($this->sources() as $path => $contents) {
            foreach ($hosts as $host) {
                $this->assertStringNotContainsString(
                    $host,
                    $contents,
                    "A cloud AI endpoint appears in {$path}.",
                );
            }
        }
    }

    public function test_the_ai_endpoint_stays_local(): void
    {
        $this->assertTrue(
            app(LocalEndpointGuard::class)->isLocal(config('ai.ollama.url')),
            'OLLAMA_URL must be a local address.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* No real email transport */
    /* ------------------------------------------------------------------ */

    public function test_the_only_email_service_is_the_local_simulated_one(): void
    {
        $service = app(EmailServiceInterface::class);

        $this->assertInstanceOf(LocalTestEmailService::class, $service);
        $this->assertTrue($service->isSimulated());
    }

    public function test_no_other_implementation_of_the_email_interface_exists(): void
    {
        $implementations = [];

        foreach ($this->sources() as $path => $contents) {
            if (preg_match('/class\s+(\w+)\s+implements\s+EmailServiceInterface/', $contents, $m) === 1) {
                $implementations[] = $m[1];
            }
        }

        // Adding one is a deliberate act. This test is the reminder that doing
        // so means real emails can leave the machine.
        $this->assertSame(['LocalTestEmailService'], $implementations);
    }

    public function test_an_unimplemented_driver_throws_rather_than_falling_back(): void
    {
        foreach (['smtp', 'gmail', 'microsoft_graph', 'nonsense'] as $driver) {
            config(['email.driver' => $driver]);
            app()->forgetInstance(EmailServiceInterface::class);

            $threw = false;

            try {
                app(EmailServiceInterface::class);
            } catch (Throwable) {
                $threw = true;
            }

            $this->assertTrue($threw, "Driver [{$driver}] must throw, not fall back to something.");
        }
    }

    public function test_the_email_layer_never_uses_laravel_mail(): void
    {
        // Using the Mail facade would route through config/mail.php, which is
        // stock Laravel scaffolding with a real SMTP transport behind it.
        // Phase 4 must not touch it.
        foreach ($this->sources() as $path => $contents) {
            $this->assertStringNotContainsString('Facades\\Mail', $contents, "in {$path}");
            $this->assertStringNotContainsString('Mail::send', $contents, "in {$path}");
            $this->assertStringNotContainsString('Mail::to', $contents, "in {$path}");
        }
    }

    public function test_the_email_layer_makes_no_outbound_request(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Services/Email'))
        );

        $forbidden = ['Http::', 'curl_init', 'fsockopen', 'stream_socket_client', 'file_get_contents'];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "{$file->getFilename()} makes an outbound call. The email layer must not.",
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* No Phase 5 features */
    /* ------------------------------------------------------------------ */

    public function test_no_oauth_or_scheduling_route_is_registered(): void
    {
        $paths = collect(Route::getRoutes())
            ->map(fn ($route) => $route->uri())
            ->all();

        foreach (['oauth', 'callback', 'gmail', 'outlook', 'schedule', 'queue-email'] as $forbidden) {
            $this->assertNotContains($forbidden, $paths);
        }
    }

    public function test_nothing_queues_an_email_for_later_delivery(): void
    {
        // Automatic follow-ups and scheduling are a later phase. A job class
        // referencing a draft would be the first sign of one appearing.
        $this->assertDirectoryDoesNotExist(app_path('Jobs/SendEmail'));

        foreach ($this->sources() as $path => $contents) {
            $this->assertStringNotContainsString('dispatch(new Send', $contents, "in {$path}");
        }
    }
}
