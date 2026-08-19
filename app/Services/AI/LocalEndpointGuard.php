<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Exceptions\InsecureEndpointException;

/**
 * Asserts that the AI endpoint is on this machine before any request is sent.
 *
 * The privacy premise of Shvar AI Copilot is that CRM data never leaves the laptop.
 * That premise is only as good as OLLAMA_URL, so this check is a hard failure
 * rather than a warning: a typo that turned the endpoint into a public host
 * would otherwise quietly forward company, contact and lead data to a stranger.
 *
 * Checked on every call, not once at boot, because config can be changed and
 * cached between requests.
 */
class LocalEndpointGuard
{
    /**
     * @throws InsecureEndpointException
     */
    public function assertLocal(string $url): void
    {
        $host = $this->hostOf($url);
        $allowed = $this->allowedHosts();

        if (! in_array($host, $allowed, true)) {
            throw new InsecureEndpointException($host, $allowed);
        }
    }

    public function isLocal(string $url): bool
    {
        return in_array($this->hostOf($url), $this->allowedHosts(), true);
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        // A bare "localhost:11434" with no scheme parses as a path, not a host.
        if (! is_string($host) || $host === '') {
            $host = parse_url('http://'.ltrim($url, '/'), PHP_URL_HOST) ?: '';
        }

        // Strip IPv6 brackets so [::1] matches the ::1 in the allow list.
        return strtolower(trim((string) $host, '[]'));
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = config('ai.allowed_hosts', ['localhost', '127.0.0.1', '::1']);

        return array_map(strtolower(...), $hosts);
    }
}
