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
 * Two jobs beyond stripping tags:
 *
 *   1. Drop the furniture. Nav bars, cookie banners, footers and script blocks
 *      are most of a modern page by volume and none of it describes the
 *      company. Feeding them in wastes context and invites the model to
 *      "summarise" a cookie policy.
 *
 *   2. Keep the meta description and title. They are often the single clearest
 *      sentence about what a company does, written deliberately for that
 *      purpose.
 *
 * Uses DOM parsing rather than regex. Regex against HTML is a classic source of
 * silent breakage, and libxml is already available.
 */
class HtmlTextExtractor
{
    /** Elements whose content is never about the company. */
    private const STRIP_TAGS = [
        'script', 'style', 'noscript', 'svg', 'canvas', 'iframe',
        'nav', 'header', 'footer', 'form', 'button', 'select',
        'template', 'aside',
    ];

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
     * @return array{url: string, title: string|null, description: string|null, text: string}
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
        $description = $this->metaContent($xpath, 'description')
            ?? $this->metaContent($xpath, 'og:description');

        $this->stripNoise($xpath);

        $body = $this->textOf($xpath->query('//body')?->item(0) ?? $document);
        $text = $this->tidy($body);

        // A page that yields almost nothing is usually JavaScript-rendered.
        // Say so rather than sending an empty prompt to the model.
        if (mb_strlen($text) < 120 && blank($description)) {
            throw FetchFailedException::noReadableText($url);
        }

        return [
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'text' => $this->cap($text),
        ];
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

            // Share the character budget across pages so a long homepage
            // cannot crowd out the About page entirely.
            $share = max(1000, intdiv($budget, max(1, count($pages))));

            $lines[] = 'PAGE TEXT:';
            $lines[] = mb_substr($page['text'], 0, $share);

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

    private function stripNoise(DOMXPath $xpath): void
    {
        foreach (self::STRIP_TAGS as $tag) {
            $nodes = $xpath->query('//'.$tag);

            if ($nodes === false) {
                continue;
            }

            // Iterate backwards: removing from a live NodeList while walking
            // forwards skips elements.
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        foreach (self::STRIP_HINTS as $hint) {
            $query = sprintf(
                '//*[contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "%1$s")'
                .' or contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "%1$s")]',
                $hint,
            );

            $nodes = $xpath->query($query);

            if ($nodes === false) {
                continue;
            }

            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }
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
