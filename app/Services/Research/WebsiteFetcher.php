<?php

declare(strict_types=1);

namespace App\Services\Research;

use App\Services\Research\Exceptions\FetchFailedException;
use App\Services\Research\Exceptions\UnsafeUrlException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Fetches a public company website.
 *
 * The only outbound request Shvar AI Copilot makes. It sends nothing but the
 * URL - no CRM data, no prompt, no identifiers.
 *
 * Redirects are followed MANUALLY rather than by the HTTP client, because
 * every hop has to go back through PublicUrlGuard. A public URL that 302s to
 * http://169.254.169.254 is the standard way to defeat a check that only looks
 * at the address the user typed.
 */
class WebsiteFetcher
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly PublicUrlGuard $guard,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('research.enabled', true);
    }

    /**
     * Fetch one page.
     *
     * @return array{url: string, status: int, html: string, content_type: string}
     *
     * @throws UnsafeUrlException|FetchFailedException
     */
    public function fetch(string $url): array
    {
        $target = $this->guard->assertSafe($url);
        $limit = (int) config('research.fetch.max_redirects', 4);

        for ($hop = 0; $hop <= $limit; $hop++) {
            $response = $this->request($target);

            if ($this->isRedirect($response)) {
                $location = $response->header('Location');

                if ($location === '' || $location === null) {
                    throw FetchFailedException::httpError($target, $response->status());
                }

                // Re-validate the destination. This is the whole reason
                // redirects are not delegated to the HTTP client.
                $target = $this->guard->assertSafe($this->absolutise($location, $target));

                continue;
            }

            if ($response->failed()) {
                throw FetchFailedException::httpError($target, $response->status());
            }

            $contentType = strtolower((string) $response->header('Content-Type'));

            if (! $this->isReadable($contentType)) {
                throw FetchFailedException::unsupportedContent($target, $contentType ?: 'unknown');
            }

            return [
                'url' => $target,
                'status' => $response->status(),
                'html' => $this->cap($response->body()),
                'content_type' => $contentType,
            ];
        }

        throw FetchFailedException::tooManyRedirects($url, $limit);
    }

    /**
     * Fetch the homepage and, if configured, an About page.
     *
     * Company sites very often keep the useful description one click in, so the
     * homepage alone frequently yields a slogan and nothing else. Failures on
     * the extra pages are ignored - only the first page is required.
     *
     * @return list<array{url: string, status: int, html: string, content_type: string}>
     *
     * @throws UnsafeUrlException|FetchFailedException
     */
    public function fetchSite(string $url): array
    {
        // The first fetch must succeed; its exception is the one worth showing.
        $first = $this->fetch($url);
        $pages = [$first];

        $maxPages = (int) config('research.extract.max_pages', 2);

        if ($maxPages <= 1) {
            return $pages;
        }

        $base = $first['url'];
        $seen = [$this->normalise($base) => true];

        foreach ((array) config('research.extract.candidate_paths', []) as $path) {
            if (count($pages) >= $maxPages) {
                break;
            }

            $candidate = $this->absolutise((string) $path, $base);
            $key = $this->normalise($candidate);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            try {
                $page = $this->fetch($candidate);
            } catch (UnsafeUrlException|FetchFailedException) {
                // An About page that 404s is entirely normal. Keep going.
                continue;
            }

            // Dedupe on the FINAL url, not the requested one. Sites routinely
            // redirect "/" to a locale root such as "/en", so the candidate
            // list would otherwise fetch and feed the homepage to the model
            // twice - wasting the character budget on a duplicate.
            $finalKey = $this->normalise($page['url']);

            if (isset($seen[$finalKey])) {
                continue;
            }

            $seen[$finalKey] = true;
            $pages[] = $page;
        }

        return $pages;
    }

    /** @throws FetchFailedException */
    private function request(string $url): Response
    {
        $timeout = (int) config('research.fetch.timeout', 20);

        try {
            return $this->http
                ->withHeaders([
                    // Honest identification - see config/research.php.
                    'User-Agent' => (string) config('research.fetch.user_agent'),
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.1',
                    'Accept-Language' => 'en',
                ])
                ->timeout($timeout)
                ->connectTimeout((int) config('research.fetch.connect_timeout', 8))
                // Manual, so PublicUrlGuard sees every hop.
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException $e) {
            throw $this->isTimeout($e)
                ? FetchFailedException::timedOut($url, $timeout)
                : FetchFailedException::unreachable($url, $e->getMessage());
        }
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function isReadable(string $contentType): bool
    {
        foreach ((array) config('research.fetch.allowed_content_types', []) as $allowed) {
            if (str_contains($contentType, (string) $allowed)) {
                return true;
            }
        }

        // A server that sends no Content-Type at all is given the benefit of
        // the doubt; the extractor will reject it if there is no real text.
        return $contentType === '';
    }

    /** Resolves a possibly-relative Location or path against the current URL. */
    private function absolutise(string $location, string $base): string
    {
        $location = trim($location);

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $root = "{$scheme}://{$host}{$port}";

        if (str_starts_with($location, '/')) {
            return $root.$location;
        }

        $path = rtrim(dirname($parts['path'] ?? '/'), '/');

        return "{$root}{$path}/{$location}";
    }

    private function normalise(string $url): string
    {
        return rtrim(strtolower($url), '/');
    }

    /**
     * Truncate a page rather than trusting Content-Length.
     *
     * A hostile or misconfigured server can stream far more than it declares.
     */
    private function cap(string $body): string
    {
        $max = (int) config('research.fetch.max_bytes', 2_000_000);

        return strlen($body) > $max ? substr($body, 0, $max) : $body;
    }

    private function isTimeout(ConnectionException $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['timed out', 'timeout', 'operation too slow'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
