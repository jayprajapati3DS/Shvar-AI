<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Company research (website fetching)
|--------------------------------------------------------------------------
|
| This is the ONE feature in Shvar AI Copilot that makes an outbound request.
|
| It fetches the company website you typed, so the local model has real text to
| work from instead of inventing facts from memory. Your CRM data is never sent
| anywhere - the request carries only the URL, exactly as a browser visiting
| that page would.
|
| It can be switched off entirely, restoring the zero-outbound-request posture.
|
*/

return [

    /*
    | Master switch. With this false, the Research button is disabled and the
    | application makes no outbound requests at all.
    */
    'enabled' => (bool) env('RESEARCH_FETCH_ENABLED', true),

    'fetch' => [
        // Seconds. A slow corporate site should fail fast, not hang the request.
        'timeout' => (int) env('RESEARCH_TIMEOUT', 20),
        'connect_timeout' => (int) env('RESEARCH_CONNECT_TIMEOUT', 8),

        // Hard cap on how much of a page is read. Protects against a hostile or
        // broken server streaming gigabytes at us.
        'max_bytes' => (int) env('RESEARCH_MAX_BYTES', 2_000_000),

        // Redirects are followed manually, revalidating the destination at each
        // hop - a redirect is the classic way to smuggle a request to an
        // internal address past a naive check.
        'max_redirects' => (int) env('RESEARCH_MAX_REDIRECTS', 4),

        /*
        | Honest identification. A site owner reading their logs should be able
        | to tell what visited them and why, rather than seeing a browser
        | impersonation. Do not disguise this as Chrome.
        */
        'user_agent' => env(
            'RESEARCH_USER_AGENT',
            'ShvarAICopilot/1.0 (private CRM research; one page per lookup)',
        ),

        // Only these. No file://, no ftp://, no gopher://.
        'allowed_schemes' => ['http', 'https'],

        // Content types worth reading. Anything else is rejected before download.
        'allowed_content_types' => ['text/html', 'application/xhtml+xml', 'text/plain'],
    ],

    /*
    | How much page text reaches the model. A large page would otherwise blow
    | past the context window and time the model out on CPU.
    */
    'extract' => [
        'max_chars' => (int) env('RESEARCH_MAX_CHARS', 12000),

        // Pages worth trying beyond the homepage, in order. Many company sites
        // keep the useful description on an About page rather than the root.
        'candidate_paths' => ['/', '/about', '/about-us', '/company'],

        // How many of those to actually fetch. Each one is a request and adds
        // latency, so this is deliberately small.
        'max_pages' => (int) env('RESEARCH_MAX_PAGES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | SSRF protection
    |--------------------------------------------------------------------------
    |
    | The URL comes from a form, so it is untrusted input pointed at the network.
    | Without this, someone could aim it at http://localhost:11434 (your Ollama),
    | your router's admin page, a NAS, or a cloud metadata endpoint - and read
    | the response back through the UI.
    |
    | Every hostname is resolved and every resulting IP checked before a socket
    | is opened, and again after each redirect.
    |
    */
    'blocked_cidrs' => [
        // Loopback - this is what stops the app being pointed at its own Ollama.
        '127.0.0.0/8',
        '::1/128',

        // Private networks (RFC 1918 / RFC 4193).
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',

        // Link-local. 169.254.169.254 is the cloud metadata address; on a
        // hosted box this is the single most dangerous target.
        '169.254.0.0/16',
        'fe80::/10',

        // "This host on this network", carrier-grade NAT, benchmarking,
        // documentation ranges, multicast and reserved space.
        '0.0.0.0/8',
        '100.64.0.0/10',
        '198.18.0.0/15',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        'ff00::/8',
    ],

    /*
    | Hostnames rejected outright, before DNS is even consulted.
    */
    'blocked_hosts' => [
        'localhost',
        'metadata.google.internal',
        'metadata.goog',
        'instance-data',
    ],
];
