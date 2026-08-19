<?php

declare(strict_types=1);

namespace App\Services\Research;

use App\Services\Research\Exceptions\FetchFailedException;
use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Turns fetched HTML into plain text the model can read.
 *
 * Three jobs beyond stripping tags:
 *
 *   1. Drop the furniture. Nav bars, cookie banners and script blocks are most
 *      of a modern page by volume and none of it describes the company.
 *      Feeding them in wastes context and invites the model to "summarise" a
 *      cookie policy.
 *
 *      Carefully, though: the naive version of this deleted 88% of a real
 *      customer site, because a WordPress theme wrapped the whole article in a
 *      div whose class contained "sidebar". Hence MAX_NOISE_SHARE - an element
 *      holding most of the page is layout, not ornament.
 *
 *   2. Keep the meta description and title. Often the single clearest sentence
 *      about what a company does, written deliberately for that purpose.
 *
 *   3. Harvest JSON-LD and og: metadata BEFORE scripts are stripped. A
 *      JavaScript-rendered site may contain no prose at all, yet still ship a
 *      complete Organization block in its initial HTML - name, description,
 *      even a postal address. On such sites this is the only usable source.
 *
 * Uses DOM parsing rather than regex. Regex against HTML is a classic source of
 * silent breakage, and libxml is already available.
 */
class HtmlTextExtractor
{
    /**
     * Class/id fragments that mark boilerplate. Matched case-insensitively as
     * substrings, so "cookie-banner-wrapper" and "CookieConsent" both go.
     */
    private const STRIP_HINTS = [
        'cookie', 'consent', 'gdpr', 'newsletter', 'subscribe',
        'breadcrumb', 'pagination', 'sidebar', 'menu', 'navbar',
        'social', 'share', 'popup', 'modal', 'banner', 'skip-link',
    ];

    /**
     * @param  array{url: string, html: string}  $page
     * @return array{url: string, title: string|null, description: string|null, structured: list<string>, text: string}
     *
     * @throws FetchFailedException
     */
    public function extract(array $page): array
    {
        $html = $page['html'];
        $url = $page['url'];

        if (trim($html) === '') {
            throw FetchFailedException::noReadableText($url);
        }

        $document = $this->parse($html);
        $xpath = new DOMXPath($document);

        $title = $this->firstText($xpath, '//title');
        $description = $this->bestDescription($xpath);

        // Harvested BEFORE stripNoise removes every <script>. A JavaScript-only
        // site renders no prose at all, but very often still ships a JSON-LD
        // Organization block and a full set of og: tags in the initial HTML -
        // which is frequently the clearest description of the company anywhere
        // on the page. Discarding it was throwing away the best source.
        $structured = $this->structuredFacts($xpath);

        $this->stripNoise($xpath);

        $body = $this->textOf($xpath->query('//body')?->item(0) ?? $document);
        $text = $this->tidy($body);

        // Only give up when there is nothing at all: no prose, no description,
        // no structured data. A JS site with good metadata is still usable.
        if (mb_strlen($text) < 120 && blank($description) && $structured === []) {
            throw FetchFailedException::noReadableText($url);
        }

        return [
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'structured' => $structured,
            'text' => $this->cap($text),
        ];
    }

    /**
     * The best available one-line description.
     *
     * Ordered by how deliberately each is written about the company.
     */
    private function bestDescription(DOMXPath $xpath): ?string
    {
        foreach (['description', 'og:description', 'twitter:description'] as $name) {
            $value = $this->metaContent($xpath, $name);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Facts from JSON-LD and social metadata, as labelled lines.
     *
     * These are machine-readable statements the site publishes about itself, so
     * they are quotable evidence in the same way body text is - and on a
     * JavaScript-rendered site they may be the only thing available.
     *
     * @return list<string>
     */
    private function structuredFacts(DOMXPath $xpath): array
    {
        $facts = [];

        foreach (['og:site_name', 'og:title', 'og:type', 'og:locale', 'keywords', 'author'] as $name) {
            $value = $this->metaContent($xpath, $name);

            if ($value !== null) {
                $facts[] = ucfirst(str_replace(['og:', '_'], ['', ' '], $name)).': '.$value;
            }
        }

        foreach ($this->jsonLdBlocks($xpath) as $block) {
            foreach ($this->flattenJsonLd($block) as $fact) {
                $facts[] = $fact;
            }
        }

        return array_values(array_unique($facts));
    }

    /**
     * Decoded <script type="application/ld+json"> payloads.
     *
     * @return list<array<string, mixed>>
     */
    private function jsonLdBlocks(DOMXPath $xpath): array
    {
        $nodes = $xpath->query('//script[contains(@type, "ld+json")]');

        if ($nodes === false) {
            return [];
        }

        $blocks = [];

        foreach ($nodes as $node) {
            $decoded = json_decode(trim($node->textContent), true);

            if (! is_array($decoded)) {
                continue;
            }

            // A block is one object, a list of objects, or an @graph wrapper.
            $candidates = isset($decoded['@graph']) && is_array($decoded['@graph'])
                ? $decoded['@graph']
                : (array_is_list($decoded) ? $decoded : [$decoded]);

            foreach ($candidates as $candidate) {
                if (is_array($candidate)) {
                    $blocks[] = $candidate;
                }
            }
        }

        return $blocks;
    }

    /**
     * Pull the company-describing fields out of one JSON-LD object.
     *
     * Only Organization-like types are read. A BreadcrumbList or WebPage says
     * nothing about the company and would just add noise.
     *
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    private function flattenJsonLd(array $block): array
    {
        $type = $block['@type'] ?? '';
        $type = is_array($type) ? implode(' ', $type) : (string) $type;

        $organisationTypes = ['Organization', 'Corporation', 'LocalBusiness', 'MedicalOrganization', 'EducationalOrganization'];

        $isOrganisation = false;

        foreach ($organisationTypes as $candidate) {
            if (stripos($type, $candidate) !== false) {
                $isOrganisation = true;
                break;
            }
        }

        if (! $isOrganisation) {
            return [];
        }

        $facts = [];

        foreach (['name' => 'Name', 'legalName' => 'Legal name', 'description' => 'Description', 'slogan' => 'Slogan'] as $key => $label) {
            $value = $this->scalar($block[$key] ?? null);

            if ($value !== null) {
                $facts[] = "{$label}: {$value}";
            }
        }

        // Address is the one place a JS-only site reliably states a location -
        // and location is exactly what the model is otherwise tempted to guess.
        $address = $block['address'] ?? null;

        if (is_array($address)) {
            foreach ([
                'addressCountry' => 'Country',
                'addressRegion' => 'State/region',
                'addressLocality' => 'City',
                'streetAddress' => 'Street',
            ] as $key => $label) {
                $value = $this->scalar($address[$key] ?? null);

                if ($value !== null) {
                    $facts[] = "{$label}: {$value}";
                }
            }
        } elseif (($plain = $this->scalar($address)) !== null) {
            $facts[] = "Address: {$plain}";
        }

        foreach (['industry' => 'Industry', 'naics' => 'NAICS', 'foundingDate' => 'Founded', 'numberOfEmployees' => 'Employees'] as $key => $label) {
            $value = $this->scalar($block[$key] ?? null);

            if ($value !== null) {
                $facts[] = "{$label}: {$value}";
            }
        }

        return $facts;
    }

    /** JSON-LD values arrive as strings, nested objects, or single-item lists. */
    private function scalar(mixed $value): ?string
    {
        if (is_array($value)) {
            // {"@value": "..."} and ["..."] both occur.
            $value = $value['@value'] ?? $value['name'] ?? (array_is_list($value) ? reset($value) : null);
        }

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $string === '' ? null : mb_substr($string, 0, 400);
    }

    /**
     * Render extracted pages as the prompt's source block.
     *
     * Each page is labelled with its URL so the model can cite where a fact
     * came from, and so the user can check it.
     *
     * @param  list<array{url: string, title: string|null, description: string|null, text: string}>  $pages
     */
    public function render(array $pages): string
    {
        $blocks = [];
        $budget = (int) config('research.extract.max_chars', 12000);

        foreach ($pages as $page) {
            $lines = ["SOURCE PAGE: {$page['url']}"];

            if (filled($page['title'])) {
                $lines[] = "PAGE TITLE: {$page['title']}";
            }

            if (filled($page['description'])) {
                $lines[] = "META DESCRIPTION: {$page['description']}";
            }

            // Structured data the site publishes about itself. On a
            // JavaScript-rendered page this is often the only real content, so
            // it goes in ahead of the body text.
            if (($page['structured'] ?? []) !== []) {
                $lines[] = 'SITE METADATA:';

                foreach ($page['structured'] as $fact) {
                    $lines[] = "  {$fact}";
                }
            }

            // Share the character budget across pages so a long homepage
            // cannot crowd out the About page entirely.
            $share = max(1000, intdiv($budget, max(1, count($pages))));

            if (trim($page['text']) !== '') {
                $lines[] = 'PAGE TEXT:';
                $lines[] = mb_substr($page['text'], 0, $share);
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n".str_repeat('-', 60)."\n\n", $blocks);
    }

    private function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;

        // Malformed markup is the norm, not the exception. Suppress libxml's
        // complaints and work with whatever tree it manages to build.
        $previous = libxml_use_internal_errors(true);

        // The meta hint stops libxml assuming Latin-1 and mangling accents.
        $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * Largest share of the page's text a "noise" element may hold before it is
     * treated as layout rather than furniture.
     *
     * Learned the hard way: a WordPress theme wrapped its entire article in a
     * div whose class contained "sidebar" (a layout modifier such as
     * `no-sidebar`), and the naive hint match deleted 88% of the page - leaving
     * "the page contained almost no readable text" on a site that was perfectly
     * readable. A cookie banner is small; a layout wrapper is not.
     */
    private const MAX_NOISE_SHARE = 0.30;

    private function stripNoise(DOMXPath $xpath): void
    {
        // Never content, whatever their size.
        foreach (['script', 'style', 'noscript', 'svg', 'canvas', 'iframe', 'template'] as $tag) {
            $this->removeAll($xpath->query('//'.$tag));
        }

        $pageChars = $this->textLength($xpath->query('//body')?->item(0));

        // Structural furniture. Usually safe to drop, but a minimal theme may
        // put the whole article inside <header> or <aside>, so the same
        // proportion guard applies.
        foreach (['nav', 'header', 'footer', 'form', 'button', 'select', 'aside'] as $tag) {
            $this->removeAll($xpath->query('//'.$tag), $pageChars);
        }

        foreach (self::STRIP_HINTS as $hint) {
            $query = sprintf(
                '//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "%1$s")'
                .' or contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "%1$s")]',
                $hint,
            );

            $this->removeAll($xpath->query($query), $pageChars);
        }
    }

    /**
     * Remove every node in a list, optionally sparing any that holds too much
     * of the page to plausibly be furniture.
     *
     * @param  \DOMNodeList<DOMNode>|false  $nodes
     * @param  int|null  $pageChars  Null disables the proportion guard.
     */
    private function removeAll(mixed $nodes, ?int $pageChars = null): void
    {
        if ($nodes === false) {
            return;
        }

        // Backwards: removing from a live NodeList while walking forwards skips
        // elements.
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);

            if ($node === null) {
                continue;
            }

            if ($pageChars !== null && $pageChars > 0) {
                $share = $this->textLength($node) / $pageChars;

                if ($share > self::MAX_NOISE_SHARE) {
                    // This is the page, not an ornament on it.
                    continue;
                }
            }

            $node->parentNode?->removeChild($node);
        }
    }

    private function textLength(?DOMNode $node): int
    {
        if ($node === null) {
            return 0;
        }

        return mb_strlen(trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? ''));
    }

    private function textOf(DOMNode $node): string
    {
        // Block-level elements need a separator, or headings run into the
        // paragraph beneath them and produce sentences that never existed.
        $html = $node->ownerDocument?->saveHTML($node) ?? '';
        $html = preg_replace('#<(/?)(p|div|br|li|tr|h[1-6]|section|article)\b[^>]*>#i', "\n", $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)?->item(0);

        if ($node === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

        return $value === '' ? null : mb_substr($value, 0, 300);
    }

    private function metaContent(DOMXPath $xpath, string $name): ?string
    {
        $node = $xpath->query(sprintf(
            '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%1$s"'
            .' or translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%1$s"]',
            strtolower($name),
        ))?->item(0);

        if (! $node instanceof \DOMElement) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $node->getAttribute('content')) ?? '');

        return $value === '' ? null : mb_substr($value, 0, 500);
    }

    /** Collapses the whitespace explosion that stripping tags leaves behind. */
    private function tidy(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        // Drop lines too short to be a sentence - leftover menu items and
        // stray labels that survived the structural strip.
        $lines = array_filter(
            array_map(trim(...), explode("\n", $text)),
            fn (string $line) => mb_strlen($line) > 2,
        );

        return trim(implode("\n", $lines));
    }

    private function cap(string $text): string
    {
        $max = (int) config('research.extract.max_chars', 12000);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }
}
