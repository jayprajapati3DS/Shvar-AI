<?php

declare(strict_types=1);

namespace App\Services\Research\Exceptions;

/** The site was reachable in principle but produced nothing usable. */
class FetchFailedException extends ResearchException
{
    private string $userMessage = 'The website could not be read.';

    private ?string $hint = 'Try again, or add the company details by hand.';

    public static function unreachable(string $url, string $detail): self
    {
        $e = new self("Could not reach [{$url}]: {$detail}");
        $e->userMessage = 'That website could not be reached.';
        $e->hint = 'Check the address and that you are online. Some sites also block automated visitors.';

        return $e;
    }

    public static function timedOut(string $url, int $seconds): self
    {
        $e = new self("Fetch of [{$url}] exceeded {$seconds}s");
        $e->userMessage = "The website did not respond within {$seconds} seconds.";
        $e->hint = 'Try again, or enter the company details by hand.';

        return $e;
    }

    public static function httpError(string $url, int $status): self
    {
        $e = new self("Fetch of [{$url}] returned HTTP {$status}");

        $e->userMessage = match (true) {
            $status === 404 => 'That page does not exist on the site.',
            $status === 403 || $status === 401 => 'The website refused the request.',
            $status === 429 => 'The website asked us to slow down.',
            $status >= 500 => 'The website returned a server error.',
            default => "The website returned an unexpected response (HTTP {$status}).",
        };

        $e->hint = $status === 403 || $status === 401
            ? 'Some sites block automated visitors. Paste the About text into Notes instead.'
            : 'Try the homepage address, or enter the details by hand.';

        return $e;
    }

    public static function unsupportedContent(string $url, string $contentType): self
    {
        $e = new self("Fetch of [{$url}] returned unsupported content type [{$contentType}]");
        $e->userMessage = 'That address is not a readable web page.';
        $e->hint = 'Point it at the company homepage or About page rather than a file or download.';

        return $e;
    }

    public static function tooManyRedirects(string $url, int $limit): self
    {
        $e = new self("Fetch of [{$url}] exceeded {$limit} redirects");
        $e->userMessage = 'That address redirected too many times.';

        return $e;
    }

    public static function noReadableText(string $url): self
    {
        $e = new self("No usable text extracted from [{$url}]");
        $e->userMessage = 'The page loaded but contained almost no readable text.';
        $e->hint = 'Sites built entirely in JavaScript often look empty to a simple fetch. '
            .'Enter the company details by hand, or try their About page directly.';

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
