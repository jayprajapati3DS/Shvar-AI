<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\EmailLength;
use App\Enums\EmailTone;
use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEmailSettingsRequest;
use App\Http\Requests\UpdateSmtpSettingsRequest;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\EmailSettings;
use App\Services\Email\EmailStyleProfile;
use App\Services\Email\RecipientAllowlist;
use App\Services\Email\SmtpEmailService;
use App\Services\Email\SmtpSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sender profile and writing preferences.
 *
 * Everything here is local and everything here is optional except, in practice,
 * a name - an unsigned outreach email looks broken in a way a missing phone
 * number does not, which is why the quality check warns about it.
 *
 * The signature is emphatically NOT AI-generated: the preview on this page is
 * built by EmailRenderer from these exact fields, so what you see is what gets
 * appended.
 */
class EmailSettingsController extends Controller
{
    use RedirectsToOrigin;

    public function __construct(
        private readonly EmailSettings $settings,
        private readonly EmailRenderer $renderer,
        private readonly EmailServiceInterface $mailer,
        private readonly SmtpSettings $smtp,
        private readonly RecipientAllowlist $allowlist,
        private readonly EmailStyleProfile $style,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Email', [
            'settings' => $this->settings->toArray(),
            'options' => [
                'tones' => EmailTone::options(),
                'lengths' => EmailLength::options(),
            ],
            // A worked example so the effect of each field is visible before
            // it lands in a real email.
            'preview' => $this->renderer->withSignature(
                "Hi Dana,\n\n"
                ."…the body of your email would appear here…\n\n"
                .'Best regards,'
            ),
            'sending' => [
                'simulated' => $this->mailer->isSimulated(),
                'mode' => $this->mailer->mode(),
                'description' => $this->mailer->description(),
                'allowed' => (bool) config('email.allow_test_send', false),
                'driver' => (string) config('email.driver', 'local'),
            ],

            // Connection settings. Note what is NOT in here: the password.
            // SmtpSettings::toArray() reports whether one is set and never what
            // it is, so it cannot leak through this payload.
            'smtp' => $this->smtp->toArray(),

            // Read-only. The allowlist is a safety rail, and a rail you can
            // remove by clicking is not much of one - it comes from .env, and
            // this page shows it rather than editing it. Same treatment as
            // OLLAMA_URL on the AI settings page.
            // What the application has concluded about how you write, shown in
            // full. A prompt that silently rewrites itself is how a system
            // quietly gets worse in ways nobody can point at.
            'learning' => $this->style->toArray(),

            'allowlist' => [
                ...$this->allowlist->describe(),
                'read_only' => true,
                'env_key' => 'EMAIL_ALLOWED_RECIPIENTS',
            ],
        ]);
    }

    /**
     * Save the SMTP connection.
     *
     * Separate from update() because it writes a credential rather than a
     * preference, and because the password needs the UNCHANGED sentinel - the
     * form was never given the real one to send back.
     */
    public function updateSmtp(UpdateSmtpSettingsRequest $request): RedirectResponse
    {
        $this->smtp->save($request->validated());

        return $this->backTo('settings.email.index')->with('success', 'SMTP settings saved. Test the connection before sending.');
    }

    /** Forget every SMTP setting, password included. */
    public function forgetSmtp(): RedirectResponse
    {
        $this->smtp->forget();

        return $this->backTo('settings.email.index')->with('success', 'SMTP settings cleared.');
    }

    /**
     * Verify the connection without sending anything.
     *
     * Opens the transport, authenticates, stops. The mail equivalent of "Test
     * AI Connection": it answers "are these credentials right" without putting
     * a message in anyone's inbox.
     */
    public function testSmtp(): RedirectResponse
    {
        $service = new SmtpEmailService(
            $this->renderer,
            $this->smtp,
            $this->settings,
            $this->allowlist,
        );

        $result = $service->testConnection();

        return $result['ok']
            ? back()->with('success', $result['message'])
            : back()->with('error', 'Could not connect: '.$result['message']);
    }

    public function update(UpdateEmailSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->validated());

        return $this->backTo('settings.email.index')->with('success', 'Email settings saved.');
    }

    /** Drop the hand-written signature so the composed one applies again. */
    public function resetSignature(): RedirectResponse
    {
        $this->settings->save(['signature' => null]);

        return $this->backTo('settings.email.index')->with('success', 'Signature reset. It is now composed from your profile fields.');
    }
}
