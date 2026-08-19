<?php

declare(strict_types=1);

namespace App\Services\Research\Exceptions;

/**
 * The URL was rejected before any socket was opened.
 *
 * The user-facing messages here are deliberately vague about internal network
 * topology - telling someone "10.0.0.5 is blocked" confirms what exists on the
 * network. The technical message carries the detail for the local log.
 */
class UnsafeUrlException extends ResearchException
{
    private string $userMessage = 'That web address cannot be used.';

    private ?string $hint = null;

    public static function malformed(string $url, string $why): self
    {
        $e = new self("Malformed research URL [{$url}]: {$why}");
        $e->userMessage = "That does not look like a valid website address - {$why}.";
        $e->hint = 'Enter the company website, for example acme-medical.com';

        return $e;
    }

    public static function scheme(string $url, string $scheme): self
    {
        $e = new self("Disallowed scheme [{$scheme}] in research URL [{$url}]");
        $e->userMessage = 'Only http and https addresses can be researched.';

        return $e;
    }

    public static function blockedHost(string $url, string $host): self
    {
        $e = new self("Blocked host [{$host}] in research URL [{$url}]");
        $e->userMessage = 'That address points at this computer rather than a company website.';
        $e->hint = 'Research only fetches public company websites.';

        return $e;
    }

    public static function privateAddress(string $url, string $host, string $ip): self
    {
        // Technical message names the IP; the user message does not.
        $e = new self("Research URL [{$url}] resolves to non-public address [{$ip}] via host [{$host}]");
        $e->userMessage = 'That address resolves to a private or internal network, so it was not fetched.';
        $e->hint = 'Research is restricted to public websites. This protects your machine and local network.';

        return $e;
    }

    public static function unresolvable(string $url, string $host): self
    {
        $e = new self("Could not resolve host [{$host}] for research URL [{$url}]");
        $e->userMessage = "The domain \"{$host}\" could not be found.";
        $e->hint = 'Check the spelling, or that you are online.';

        return $e;
    }

    public function userMessage(): string
    {
        return $this->userMessage;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }
}
