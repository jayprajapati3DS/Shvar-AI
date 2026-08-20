<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Http\Requests\BulkDeleteRequest;
use App\Models\Company;
use App\Models\EmailDraft;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clearing out drafts a selection at a time.
 *
 * Regenerating an email three times leaves nine drafts. Removing those one at a
 * time is tedious enough that they get left there instead, which is how a list
 * meant for reviewing what to send fills up with what you already decided
 * against.
 *
 * The care here is about Sent drafts. They are deletable - refusing to let
 * someone delete their own records would be presumptuous - but a sent draft is
 * the only trace this application keeps of an email that genuinely left the
 * machine, so losing a run of them to a stray "select all" is named before and
 * after the click rather than passing quietly.
 */
class BulkDeleteDraftsTest extends TestCase
{
    use RefreshDatabase;

    private function draft(EmailDraftStatus $status = EmailDraftStatus::Draft): EmailDraft
    {
        $lead = Lead::factory()->create([
            'company_id' => Company::factory(),
            'first_name' => 'Dana',
            'last_name' => 'Whitfield',
            'email' => 'dana@example.test',
        ]);

        $body = "Hi Dana,\n\nA short note about case review.\n\nBest regards,";

        return EmailDraft::create([
            'lead_id' => $lead->id,
            'product_id' => Product::factory()->create()->id,
            'variant' => EmailVariant::ProfessionalDirect,
            'status' => $status,
            'subject' => 'Case review workflow',
            'body' => $body,
            'ai_subject' => 'Case review workflow',
            'ai_body' => $body,
            'recipient_email' => 'dana@example.test',
            'recipient_name' => 'Dana Whitfield',
            'word_count' => EmailDraft::countWords($body),
            'version' => 1,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The thing itself */
    /* ------------------------------------------------------------------ */

    public function test_a_selection_of_drafts_is_deleted(): void
    {
        $drafts = collect(range(1, 3))->map(fn () => $this->draft());
        $keep = $this->draft();

        $this->from(route('email-drafts.index'))
            ->post(route('email-drafts.bulk-destroy'), ['ids' => $drafts->pluck('id')->all()])
            ->assertRedirect(route('email-drafts.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Deleted 3 draft(s)'));

        $this->assertDatabaseCount('email_drafts', 1);
        $this->assertDatabaseHas('email_drafts', ['id' => $keep->id]);
    }

    public function test_deleting_keeps_the_filters_you_selected_them_with(): void
    {
        $draft = $this->draft();

        // The view you built to find these is the view you want back.
        $this->from(route('email-drafts.index', ['status' => 'Draft']))
            ->post(route('email-drafts.bulk-destroy'), ['ids' => [$draft->id]])
            ->assertRedirect(route('email-drafts.index', ['status' => 'Draft']));
    }

    /* ------------------------------------------------------------------ */
    /* Sent drafts */
    /* ------------------------------------------------------------------ */

    public function test_a_sent_draft_can_be_deleted_but_is_called_out(): void
    {
        $sent = $this->draft(EmailDraftStatus::Sent);
        $unsent = $this->draft();

        $this->post(route('email-drafts.bulk-destroy'), [
            'ids' => [$sent->id, $unsent->id],
        ])->assertSessionHas('success', function (string $message): bool {
            // Both halves matter: that it happened, and what was lost with it.
            return str_contains($message, 'Deleted 2 draft(s)')
                && str_contains($message, '1 that had already been sent')
                && str_contains($message, 'no longer recorded');
        });

        $this->assertDatabaseCount('email_drafts', 0);
    }

    public function test_a_selection_with_nothing_sent_says_nothing_about_sending(): void
    {
        $draft = $this->draft();

        $this->post(route('email-drafts.bulk-destroy'), ['ids' => [$draft->id]])
            ->assertSessionHas('success', fn (string $m) => ! str_contains($m, 'sent'));
    }

    /* ------------------------------------------------------------------ */
    /* The selection itself */
    /* ------------------------------------------------------------------ */

    public function test_an_empty_selection_is_refused(): void
    {
        $this->post(route('email-drafts.bulk-destroy'), ['ids' => []])
            ->assertSessionHasErrors('ids');
    }

    public function test_an_id_that_no_longer_exists_is_refused_rather_than_ignored(): void
    {
        $draft = $this->draft();

        // Silently dropping it would report "deleted 2" having deleted one. The
        // error is against the offending element, not the list.
        $this->post(route('email-drafts.bulk-destroy'), ['ids' => [$draft->id, 9999]])
            ->assertSessionHasErrors('ids.1');

        $this->assertDatabaseCount('email_drafts', 1);
    }

    public function test_a_selection_too_large_to_finish_is_refused_up_front(): void
    {
        $this->post(route('email-drafts.bulk-destroy'), [
            'ids' => range(1, BulkDeleteRequest::MAX_IDS + 1),
        ])->assertSessionHasErrors('ids');
    }

    public function test_the_same_id_twice_deletes_one_draft_and_counts_one(): void
    {
        $draft = $this->draft();

        $this->post(route('email-drafts.bulk-destroy'), [
            'ids' => [$draft->id, $draft->id],
        ])->assertSessionHas('success', fn (string $m) => str_contains($m, 'Deleted 1 draft(s)'));
    }

    /* ------------------------------------------------------------------ */
    /* What is deliberately absent */
    /* ------------------------------------------------------------------ */

    public function test_there_is_no_bulk_edit_for_drafts(): void
    {
        // A draft is a subject and a body written for one person. No field on it
        // means the same thing across forty of them, so a bulk edit here would
        // be a way to overwrite forty emails with the same sentence.
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('email-drafts.bulk-update'),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Deleting one, from wherever you were */
    /* ------------------------------------------------------------------ */

    public function test_deleting_one_from_its_own_page_goes_to_the_list(): void
    {
        $draft = $this->draft();

        $this->from(route('email-drafts.show', $draft))
            ->delete(route('email-drafts.destroy', $draft))
            ->assertRedirect(route('email-drafts.index'));
    }

    public function test_deleting_one_from_the_list_keeps_you_on_the_list(): void
    {
        $draft = $this->draft();

        $this->from(route('email-drafts.index', ['status' => 'Draft']))
            ->delete(route('email-drafts.destroy', $draft))
            ->assertRedirect(route('email-drafts.index', ['status' => 'Draft']));
    }
}
