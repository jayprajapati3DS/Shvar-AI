<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\EmailLength;
use App\Enums\EmailTone;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEmailSettingsRequest;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailServiceInterface;
use App\Services\Email\EmailSettings;
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
    public function __construct(
        private readonly EmailSettings $settings,
        private readonly EmailRenderer $renderer,
        private readonly EmailServiceInterface $mailer,
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
            ],
        ]);
    }

    public function update(UpdateEmailSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->validated());

        return back()->with('success', 'Email settings saved.');
    }

    /** Drop the hand-written signature so the composed one applies again. */
    public function resetSignature(): RedirectResponse
    {
        $this->settings->save(['signature' => null]);

        return back()->with('success', 'Signature reset. It is now composed from your profile fields.');
    }
}
