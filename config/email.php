<?php

declare(strict_types=1);

/**
 * Phase 4 email configuration.
 *
 * PRIVACY: there is no host, port, credential or API key here, because Phase 4
 * cannot send an email. The only driver is 'local', which writes to the local
 * application log. Real delivery is a later phase and will need its own
 * deliberate configuration.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | 'local'  simulates delivery, writing to the local log. Contacts nobody.
    | 'smtp'   REAL DELIVERY. Messages leave this machine. Configure the
    |          connection in Settings -> Email, and read the allowlist note
    |          below before switching.
    |
    | The provider throws on any other value rather than falling back.
    |
    | Future: 'gmail', 'microsoft_graph' (OAuth).
    |
    */

    'driver' => env('EMAIL_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Test send
    |--------------------------------------------------------------------------
    |
    | Whether the "Test send" button is offered at all. Defaults to on only
    | outside production, per section 21 of the Phase 4 brief.
    |
    */

    'allow_test_send' => env('EMAIL_ALLOW_TEST_SEND', env('APP_ENV', 'local') !== 'production'),

    /*
    |--------------------------------------------------------------------------
    | Recipient allowlist
    |--------------------------------------------------------------------------
    |
    | THE SAFETY RAIL ON REAL SENDING.
    |
    | While this is non-empty, SmtpEmailService will only deliver to addresses
    | on it and refuses everything else. Put your own address here, exercise the
    | whole flow for real, then clear it to go live.
    |
    | It lives in config rather than the settings UI on purpose. The drafts are
    | addressed to real people at real companies, and a rail you can remove by
    | clicking is not much of a rail - removing this one means opening .env.
    |
    | Comma-separated. A leading @ makes it a domain rule:
    |
    |   EMAIL_ALLOWED_RECIPIENTS="me@example.com,@mycompany.com"
    |
    | Empty means no restriction.
    |
    */

    'allowed_recipients' => env('EMAIL_ALLOWED_RECIPIENTS', ''),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'max_subject_chars' => 150,
        'max_body_chars' => 20000,
        'max_instruction_chars' => 1000,
    ],

];
