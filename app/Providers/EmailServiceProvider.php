<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Email\EmailQualityChecker;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\EmailSettings;
use App\Services\Email\LocalTestEmailService;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Wires the Phase 4 email layer.
 *
 * The point of this file mirrors AiServiceProvider: EmailServiceInterface is
 * resolved here and nowhere else, so adding SMTP, Gmail or Microsoft Graph in a
 * later phase is a change to one match arm.
 *
 * Phase 4 has exactly one implementation and it does not send anything. An
 * unrecognised driver throws rather than falling back, so a typo in
 * MAIL_TRANSPORT can never silently produce a transport nobody chose - which,
 * for a component whose job is contacting real people, is the failure mode
 * worth being loud about.
 */
class EmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Both do one query per request and cache it, or are stateless.
        $this->app->singleton(EmailSettings::class);
        $this->app->singleton(EmailRenderer::class);
        $this->app->singleton(EmailQualityChecker::class);

        $this->app->singleton(EmailServiceInterface::class, function ($app): EmailServiceInterface {
            $driver = (string) config('email.driver', 'local');

            return match ($driver) {
                'local' => new LocalTestEmailService($app->make(EmailRenderer::class)),

                // Named here so the intent is documented in code rather than
                // only in a plan: these are Phase 5, and they do not exist yet.
                'smtp', 'gmail', 'microsoft_graph' => throw new InvalidArgumentException(
                    "Email driver [{$driver}] is not implemented. Phase 4 ships simulated sending only; "
                    .'real delivery arrives in a later phase.'
                ),

                default => throw new InvalidArgumentException(
                    "Unsupported email driver [{$driver}]. Only 'local' is available."
                ),
            };
        });
    }
}
