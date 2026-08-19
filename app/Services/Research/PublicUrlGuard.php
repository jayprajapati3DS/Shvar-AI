<?php

declare(strict_types=1);

namespace App\Services\Research;

use App\Services\Research\Exceptions\UnsafeUrlException;

/**
 * Decides whether a user-supplied URL is safe to fetch.
 *
 * The exact mirror of LocalEndpointGuard. That one REQUIRES the AI endpoint to
 * be local; this one FORBIDS the research target from being local, because the
 * two have opposite threat models:
 *
 *   AI endpoint   - we choose it, and it must never be remote (data would leave)
 *   Research URL  - a user types it, and it must never be internal (SSRF)
 *
 * Without this, "Research Company" is a request forgery primitive: point it at
 * http://localhost:11434 and it reads your own Ollama; at 169.254.169.254 and it
 * reads cloud credentials; at a router's admin page and it reads your LAN.
 *
 * Defence is at the IP level, not the hostname level. Checking the string would
 * be trivially bypassed - `http://127.0.0.1.nip.io` resolves to loopback while
 * looking like an ordinary domain.
 */
class PublicUrlGuard
{
    /**
     * Validate a URL and return it normalised.
     *
     * @throws UnsafeUrlException
     */
    public function assertSafe(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw UnsafeUrlException::malformed($url, 'the address is empty');
        }

        // A bare domain is what people actually type.
        if (! preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            throw UnsafeUrlException::malformed($url, 'it is not a valid web address');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, config('research.fetch.allowed_schemes', ['http', 'https']), true)) {
            throw UnsafeUrlException::scheme($url, $scheme);
        }

        // Credentials in a URL are never legitimate here, and would be sent
        // verbatim to whatever the host turns out to be.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw UnsafeUrlException::malformed($url, 'it contains embedded credentials');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        foreach ((array) config('research.blocked_hosts', []) as $blocked) {
            if ($host === strtolower($blocked)) {
                throw UnsafeUrlException::blockedHost($url, $host);
            }
        }

        foreach ($this->resolve($host, $url) as $ip) {
            if ($this->isBlocked($ip)) {
                throw UnsafeUrlException::privateAddress($url, $host, $ip);
            }
        }

        return $url;
    }

    /** Non-throwing variant, for rendering a form hint. */
    public function isSafe(string $url): bool
    {
        try {
            $this->assertSafe($url);

            return true;
        } catch (UnsafeUrlException) {
            return false;
        }
    }

    /**
     * Every IP the hostname resolves to.
     *
     * ALL of them are checked, not just the first: a host with one public and
     * one loopback record would otherwise be reachable roughly half the time.
     *
     * @return list<string>
     *
     * @throws UnsafeUrlException
     */
    private function resolve(string $host, string $url): array
    {
        // Already an IP literal - nothing to look up.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $ips = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        // Fall back to the system resolver when dns_get_record is unavailable
        // or blocked, which happens on some Windows configurations.
        if ($ips === []) {
            $resolved = gethostbynamel($host);

            if ($resolved !== false) {
                $ips = $resolved;
            }
        }

        if ($ips === []) {
            throw UnsafeUrlException::unresolvable($url, $host);
        }

        return array_values(array_unique($ips));
    }

    /** Whether an IP falls inside any blocked range. */
    private function isBlocked(string $ip): bool
    {
        foreach ((array) config('research.blocked_cidrs', []) as $cidr) {
            if ($this->inRange($ip, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * CIDR containment, done on packed binary so IPv4 and IPv6 share one path.
     */
    private function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        $packedIp = @inet_pton($ip);
        $packedSubnet = @inet_pton((string) $subnet);

        // Different families (v4 vs v6) can never match.
        if ($packedIp === false || $packedSubnet === false
            || strlen($packedIp) !== strlen($packedSubnet)) {
            return false;
        }

        $bits = $bits === null ? strlen($packedIp) * 8 : (int) $bits;

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0
            && strncmp($packedIp, $packedSubnet, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainingBits)) - 1) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask)
            === (ord($packedSubnet[$wholeBytes]) & $mask);
    }
}
