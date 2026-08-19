<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailSetting;
use App\Services\AI\LocalEndpointGuard;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\LocalTestEmailService;
use App\Services\Email\RecipientAllowlist;
use App\Services\Email\SmtpEmailService;
use App\Services\Email\SmtpSettings;
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

    public function test_the_default_email_service_simulates(): void
    {
        // EMAIL_DRIVER is 'local' unless someone deliberately changes it, and
        // 'local' contacts nobody. Real delivery is opt-in, never a default.
        $this->assertSame('local', config('email.driver'));

        $service = app(EmailServiceInterface::class);

        $this->assertInstanceOf(LocalTestEmailService::class, $service);
        $this->assertTrue($service->isSimulated());
    }

    public function test_only_the_two_known_transports_exist(): void
    {
        $implementations = [];

        foreach ($this->sources() as $path => $contents) {
            if (preg_match('/class\s+(\w+)\s+implements\s+EmailServiceInterface/', $contents, $m) === 1) {
                $implementations[] = $m[1];
            }
        }

        sort($implementations);

        // This list grew by one when SMTP was added, which is exactly what this
        // test is for: adding a transport is the moment real email can leave the
        // machine, and it should not be possible to do it quietly. A third
        // implementation trips this again.
        $this->assertSame(['LocalTestEmailService', 'SmtpEmailService'], $implementations);
    }

    public function test_smtp_is_never_reachable_without_changing_the_driver(): void
    {
        // The service is resolved from config and nothing else. There is no
        // request parameter, setting or heuristic that can select 'smtp'.
        $this->assertInstanceOf(LocalTestEmailService::class, app(EmailServiceInterface::class));

        config(['email.driver' => 'smtp']);
        app()->forgetInstance(EmailServiceInterface::class);

        $this->assertInstanceOf(SmtpEmailService::class, app(EmailServiceInterface::class));
        $this->assertFalse(app(EmailServiceInterface::class)->isSimulated());
    }

    public function test_an_unimplemented_driver_throws_rather_than_falling_back(): void
    {
        foreach (['gmail', 'microsoft_graph', 'nonsense'] as $driver) {
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

    public function test_nothing_in_the_email_layer_calls_out_except_the_smtp_transport(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Services/Email'))
        );

        $forbidden = ['Http::', 'curl_init', 'fsockopen', 'stream_socket_client', 'file_get_contents'];

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // SmtpEmailService is the one thing here whose job is to reach the
            // network, and it does so through Symfony's transport rather than a
            // raw socket. Exempted by name so the exemption is visible, not by
            // loosening the rule for everything.
            if ($file->getFilename() === 'SmtpEmailService.php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "{$file->getFilename()} makes an outbound call. Only the SMTP transport may.",
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* SMTP credentials */
    /* ------------------------------------------------------------------ */

    public function test_the_smtp_password_is_encrypted_at_rest(): void
    {
        app(SmtpSettings::class)->save([
            'smtp_host' => 'smtp.office365.com',
            'smtp_username' => 'jay@example.test',
            'smtp_password' => 'hunter2-not-in-the-clear',
        ]);

        $stored = EmailSetting::where('key', 'smtp_password')->value('value');

        $this->assertNotNull($stored);
        $this->assertNotSame('hunter2-not-in-the-clear', $stored);
        $this->assertStringNotContainsString('hunter2', $stored);

        // ...and still readable by the one class that needs it.
        $this->assertSame('hunter2-not-in-the-clear', app(SmtpSettings::class)->password());
    }

    public function test_the_smtp_password_never_reaches_the_browser(): void
    {
        app(SmtpSettings::class)->save([
            'smtp_host' => 'smtp.office365.com',
            'smtp_username' => 'jay@example.test',
            'smtp_password' => 'hunter2-not-in-the-clear',
        ]);

        $payload = app(SmtpSettings::class)->toArray();

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertTrue($payload['password_set']);
        $this->assertStringNotContainsString(
            'hunter2',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        // And not through the settings page either.
        $response = $this->get(route('settings.email.index'))->assertOk();

        $this->assertStringNotContainsString(
            'hunter2',
            json_encode($response->viewData('page')['props'], JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_password_that_cannot_be_decrypted_reads_as_unconfigured(): void
    {
        // What happens if APP_KEY changes. The right answer is "SMTP is not set
        // up, set it again", not a 500 on the settings page.
        EmailSetting::updateOrCreate(
            ['key' => 'smtp_password'],
            ['value' => 'not-a-valid-ciphertext'],
        );

        $settings = app(SmtpSettings::class);

        $this->assertNull($settings->password());
        $this->assertFalse($settings->isConfigured());
    }

    /* ------------------------------------------------------------------ */
    /* The recipient allowlist */
    /* ------------------------------------------------------------------ */

    public function test_an_empty_allowlist_restricts_nothing(): void
    {
        config(['email.allowed_recipients' => '']);

        $allowlist = app(RecipientAllowlist::class);

        $this->assertFalse($allowlist->isRestricting());
        $this->assertTrue($allowlist->permits('anyone@anywhere.example'));
    }

    public function test_the_allowlist_permits_only_listed_addresses(): void
    {
        config(['email.allowed_recipients' => 'me@example.test, other@example.test']);

        $allowlist = app(RecipientAllowlist::class);

        $this->assertTrue($allowlist->isRestricting());
        $this->assertTrue($allowlist->permits('me@example.test'));
        $this->assertTrue($allowlist->permits('  ME@Example.TEST '));
        $this->assertFalse($allowlist->permits('prospect@realcompany.example'));
        $this->assertFalse($allowlist->permits(null));
        $this->assertFalse($allowlist->permits(''));
    }

    public function test_a_leading_at_permits_a_whole_domain(): void
    {
        config(['email.allowed_recipients' => '@3dsurgical.com']);

        $allowlist = app(RecipientAllowlist::class);

        $this->assertTrue($allowlist->permits('anyone@3dsurgical.com'));
        $this->assertFalse($allowlist->permits('anyone@3dsurgical.com.evil.example'));
        $this->assertFalse($allowlist->permits('anyone@elsewhere.example'));
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
