<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Email\EmailQualityChecker;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\EmailSettings;
use App\Services\Email\EmailStyleProfile;
use App\Services\Email\LocalTestEmailService;
use App\Services\Email\RecipientAllowlist;
use App\Services\Email\SmtpEmailService;
use App\Services\Email\SmtpSettings;
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
        $this->app->singleton(EmailStyleProfile::class);
        $this->app->singleton(SmtpSettings::class);
        $this->app->singleton(RecipientAllowlist::class);

        $this->app->singleton(EmailServiceInterface::class, function ($app): EmailServiceInterface {
            $driver = (string) config('email.driver', 'local');

            return match ($driver) {
                'local' => new LocalTestEmailService($app->make(EmailRenderer::class)),

                // REAL DELIVERY. Everything above this line produced local rows
                // a human could read and delete; this contacts people. The
                // approval check and the recipient allowlist both live inside
                // the service rather than around it.
                'smtp' => new SmtpEmailService(
                    $app->make(EmailRenderer::class),
                    $app->make(SmtpSettings::class),
                    $app->make(EmailSettings::class),
                    $app->make(RecipientAllowlist::class),
                ),

                // Named so the intent is documented in code rather than only in
                // a plan. Both need OAuth, which is a later phase.
                'gmail', 'microsoft_graph' => throw new InvalidArgumentException(
                    "Email driver [{$driver}] is not implemented. It needs OAuth, which is a later "
                    ."phase. Use 'smtp' for real delivery, or 'local' to simulate."
                ),

                default => throw new InvalidArgumentException(
                    "Unsupported email driver [{$driver}]. Available: 'local' (simulated), 'smtp' (real)."
                ),
            };
        });
    }
}
