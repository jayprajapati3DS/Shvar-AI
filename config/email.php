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
    | Only 'local' is implemented. It simulates delivery so the approval
    | workflow can be exercised without contacting anyone. The provider throws
    | on any other value rather than falling back.
    |
    | Future: 'smtp', 'gmail', 'microsoft_graph'.
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
    | Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'max_subject_chars' => 150,
        'max_body_chars' => 20000,
        'max_instruction_chars' => 1000,
    ],

];
