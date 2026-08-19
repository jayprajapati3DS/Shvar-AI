<?php

declare(strict_types=1);

namespace Tests\Feature\Research;

use App\Services\Research\Exceptions\UnsafeUrlException;
use App\Services\Research\PublicUrlGuard;
use Tests\TestCase;

/**
 * The SSRF guard.
 *
 * Website research takes a URL from a form and points the server at it. Without
 * this class that is a request-forgery primitive: aim it at localhost:11434 and
 * it reads your own Ollama; at 169.254.169.254 and it reads cloud credentials;
 * at a router and it reads your LAN - all echoed back through the UI.
 *
 * These are the highest-value tests in the feature.
 */
class PublicUrlGuardTest extends TestCase
{
    private function guard(): PublicUrlGuard
    {
        return app(PublicUrlGuard::class);
    }

    /* ---------------------------------------------------------------- */
    /* Blocked: the whole point                                         */
    /* ---------------------------------------------------------------- */

    public static function blockedUrls(): array
    {
        return [
            // The app's own AI backend - the most damaging target here.
            'ollama' => ['http://localhost:11434/api/tags'],
            'loopback ip' => ['http://127.0.0.1:11434/api/tags'],
            'loopback range' => ['http://127.99.12.3/'],
            'ipv6 loopback' => ['http://[::1]:8000/'],

            // Cloud metadata. On a hosted box this hands out credentials.
            'aws metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'gcp metadata' => ['http://metadata.google.internal/computeMetadata/v1/'],

            // Private networks - routers, NAS boxes, internal dashboards.
            'rfc1918 10' => ['http://10.0.0.1/'],
            'rfc1918 172' => ['http://172.16.5.9/'],
            'rfc1918 192' => ['http://192.168.1.1/admin'],

            // Unspecified / reserved.
            'zero address' => ['http://0.0.0.0:8000/'],
            'carrier nat' => ['http://100.64.0.1/'],

            // The app's own web server.
            'own app' => ['http://127.0.0.1:8000/settings/ai'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedUrls')]
    public function test_it_blocks_internal_addresses(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);

        $this->guard()->assertSafe($url);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockedUrls')]
    public function test_blocked_addresses_are_also_reported_as_unsafe(string $url): void
    {
        $this->assertFalse($this->guard()->isSafe($url));
    }

    public function test_it_blocks_non_http_schemes(): void
    {
        foreach (['file:///etc/passwd', 'ftp://example.com/', 'gopher://example.com/'] as $url) {
            $this->assertFalse($this->guard()->isSafe($url), "{$url} should be rejected.");
        }
    }

    public function test_it_blocks_embedded_credentials(): void
    {
        // Would be sent verbatim to whatever the host turns out to be.
        $this->expectException(UnsafeUrlException::class);

        $this->guard()->assertSafe('https://admin:hunter2@example.com/');
    }

    public function test_a_hostname_that_resolves_to_loopback_is_blocked(): void
    {
        // The attack a string check misses: an ordinary-looking domain whose
        // DNS record points at 127.0.0.1. Guarding at the IP level catches it.
        $this->expectException(UnsafeUrlException::class);

        $this->guard()->assertSafe('http://127.0.0.1.nip.io:11434/');
    }

    public function test_the_user_message_does_not_disclose_internal_addresses(): void
    {
        try {
            $this->guard()->assertSafe('http://192.168.1.1/admin');
            $this->fail('Expected UnsafeUrlException.');
        } catch (UnsafeUrlException $e) {
            // The technical message names the IP for the local log...
            $this->assertStringContainsString('192.168.1.1', $e->getMessage());
            // ...the user-facing one does not confirm what exists on the network.
            $this->assertStringNotContainsString('192.168.1.1', $e->userMessage());
            $this->assertStringContainsString('private or internal', $e->userMessage());
        }
    }

    /* ---------------------------------------------------------------- */
    /* Allowed                                                          */
    /* ---------------------------------------------------------------- */

    public function test_it_allows_ordinary_public_websites(): void
    {
        foreach (['https://example.com', 'https://www.example.com/about', 'http://example.com'] as $url) {
            $this->assertTrue($this->guard()->isSafe($url), "{$url} should be allowed.");
        }
    }

    public function test_a_bare_domain_is_normalised_to_https(): void
    {
        // What people actually type.
        $this->assertSame('https://example.com', $this->guard()->assertSafe('example.com'));
        $this->assertSame('https://example.com/about', $this->guard()->assertSafe('example.com/about'));
    }

    public function test_it_rejects_empty_and_malformed_input(): void
    {
        foreach (['', '   ', 'not a url at all', 'https://'] as $url) {
            $this->assertFalse($this->guard()->isSafe($url), "[{$url}] should be rejected.");
        }
    }

    public function test_an_unresolvable_domain_is_rejected_clearly(): void
    {
        try {
            $this->guard()->assertSafe('https://this-domain-does-not-exist-'.bin2hex(random_bytes(8)).'.example');
            $this->fail('Expected UnsafeUrlException.');
        } catch (UnsafeUrlException $e) {
            $this->assertStringContainsString('could not be found', $e->userMessage());
        }
    }

    /* ---------------------------------------------------------------- */
    /* Configuration                                                    */
    /* ---------------------------------------------------------------- */

    public function test_the_blocklist_covers_every_range_that_matters(): void
    {
        $cidrs = (array) config('research.blocked_cidrs');

        foreach ([
            '127.0.0.0/8',      // loopback - the app's own Ollama
            '10.0.0.0/8',       // private
            '172.16.0.0/12',    // private
            '192.168.0.0/16',   // private
            '169.254.0.0/16',   // link-local / cloud metadata
            '::1/128',          // ipv6 loopback
            'fc00::/7',         // ipv6 unique-local
        ] as $required) {
            $this->assertContains($required, $cidrs, "{$required} must be blocked.");
        }
    }

    public function test_the_user_agent_identifies_the_app_honestly(): void
    {
        $agent = (string) config('research.fetch.user_agent');

        // A site owner reading their logs should see what visited them.
        $this->assertStringContainsString('ShvarAICopilot', $agent);
        // Not a browser impersonation.
        $this->assertStringNotContainsString('Mozilla', $agent);
        $this->assertStringNotContainsString('Chrome', $agent);
    }
}
