<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiJobStatus;
use App\Enums\AiJobType;
use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Jobs\Ai\ClassifyEmailReply;
use App\Jobs\Ai\GenerateEmailDrafts;
use App\Jobs\Ai\ResearchCompanyWebsite;
use App\Jobs\Ai\RunLeadAnalysis;
use App\Models\AiJob;
use App\Models\Company;
use App\Models\EmailReply;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Models\Product;
use App\Services\AI\Jobs\AiJobDurations;
use App\Services\AI\Jobs\AiJobQueue;
use App\Services\AI\Jobs\QueueWorkerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Slow AI work runs in the background, and the interface can account for it.
 *
 * The complaint this answers is simple: starting an analysis meant a page you
 * could not use for five minutes. The fix is not just "put it on a queue" -
 * a queue you cannot see is worse than a spinner, because at least a spinner
 * tells you something is happening.
 *
 * So most of what is pinned here is about being answerable. Every request
 * produces a row before it produces a job. The row records what happened,
 * whether that was a result or a failure. And when there is no worker to do the
 * work, the interface says so instead of turning forever.
 */
class BackgroundAiJobTest extends TestCase
{
    use RefreshDatabase;

    private function lead(): Lead
    {
        return Lead::factory()->create([
            'company_id' => Company::factory(),
            'first_name' => 'Dana',
            'last_name' => 'Whitfield',
            'email' => 'dana@example.test',
        ]);
    }

    private function acceptedRecommendation(Lead $lead): LeadProductMatch
    {
        return LeadProductMatch::create([
            'lead_id' => $lead->id,
            'product_id' => Product::factory()->create(['active' => true])->id,
            'recommendation_type' => RecommendationType::AiPrimary,
            'status' => RecommendationStatus::Accepted,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The request returns immediately */
    /* ------------------------------------------------------------------ */

    public function test_analysing_queues_the_work_instead_of_doing_it(): void
    {
        Queue::fake();

        $lead = $this->lead();

        $this->post(route('leads.analyze', $lead))->assertRedirect();

        Queue::assertPushed(RunLeadAnalysis::class);

        // Nothing ran, so nothing was written. That is the whole point: the
        // browser request is over in milliseconds.
        $this->assertDatabaseCount('lead_analyses', 0);
    }

    public function test_a_row_exists_before_a_worker_ever_touches_it(): void
    {
        Queue::fake();

        $lead = $this->lead();

        $this->post(route('leads.analyze', $lead));

        $job = AiJob::latest('id')->firstOrFail();

        // Written by the request, not the worker. If the worker wrote it, a
        // queue with nothing running would show you an empty tray - and
        // "nothing queued" and "nothing coming" would look identical.
        $this->assertSame(AiJobStatus::Queued, $job->status);
        $this->assertSame(AiJobType::LeadAnalysis, $job->type);
        $this->assertSame('Analysing Dana Whitfield', $job->label);
        $this->assertSame($lead->id, $job->subject_id);
        $this->assertNotNull($job->queued_at);
    }

    public function test_every_slow_action_goes_to_the_queue(): void
    {
        Queue::fake();

        $lead = $this->lead();
        $recommendation = $this->acceptedRecommendation($lead);
        $company = Company::factory()->create(['website' => 'https://example.test']);
        $reply = EmailReply::create([
            'outlook_entry_id' => 'entry-1',
            'from_address' => 'dana@example.test',
            'from_name' => 'Dana Whitfield',
            'subject' => 'Re: your note',
            'body' => 'Sounds interesting, send me pricing.',
            'received_at' => now(),
        ]);

        $this->post(route('leads.emails.generate', $lead), [
            'recommendation_id' => $recommendation->id,
        ]);
        $this->post(route('companies.research', $company));
        $this->post(route('replies.classify', $reply));

        Queue::assertPushed(GenerateEmailDrafts::class);
        Queue::assertPushed(ResearchCompanyWebsite::class);
        Queue::assertPushed(ClassifyEmailReply::class);
    }

    /* ------------------------------------------------------------------ */
    /* You can walk away from it */
    /* ------------------------------------------------------------------ */

    public function test_a_running_job_is_visible_from_any_page(): void
    {
        Queue::fake();

        $lead = $this->lead();
        $this->post(route('leads.analyze', $lead));

        // The tray lives in the layout, so the state is shared with every page.
        // Started on a lead, still there on the dashboard: that is what makes
        // "go and do something else" a real instruction rather than advice.
        foreach ([route('dashboard'), route('companies.index'), route('products.index')] as $url) {
            $props = $this->get($url)->assertOk()->viewData('page')['props'];

            $this->assertSame(1, $props['ai_jobs']['active']);
            $this->assertSame('Analysing Dana Whitfield', $props['ai_jobs']['jobs'][0]['label']);
        }
    }

    public function test_the_result_carries_a_link_back_to_where_it_landed(): void
    {
        Queue::fake();

        $lead = $this->lead();
        $this->post(route('leads.analyze', $lead));

        // Recorded at dispatch, so "View result" works even if the job failed.
        $this->assertSame(
            route('leads.show', $lead),
            AiJob::latest('id')->firstOrFail()->result_url,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Not queueing the same thing twice */
    /* ------------------------------------------------------------------ */

    public function test_a_second_click_does_not_queue_a_second_run(): void
    {
        Queue::fake();

        $lead = $this->lead();

        $this->post(route('leads.analyze', $lead));

        // A button that appears to do nothing gets clicked again. Without this
        // guard that is two five-minute runs on one machine, competing for the
        // same CPU.
        $this->post(route('leads.analyze', $lead))
            ->assertSessionHas('info', fn (string $m) => str_contains($m, 'already running'));

        $this->assertSame(1, AiJob::query()->count());
        Queue::assertPushed(RunLeadAnalysis::class, 1);
    }

    public function test_a_different_lead_is_not_blocked_by_the_first(): void
    {
        Queue::fake();

        $this->post(route('leads.analyze', $this->lead()));
        $this->post(route('leads.analyze', $this->lead()));

        $this->assertSame(2, AiJob::query()->count());
    }

    public function test_finishing_frees_the_lead_for_another_run(): void
    {
        Queue::fake();

        $lead = $this->lead();

        $this->post(route('leads.analyze', $lead));
        AiJob::latest('id')->firstOrFail()->markDone('Done.');

        $this->post(route('leads.analyze', $lead))->assertRedirect();

        $this->assertSame(2, AiJob::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* When nothing is out there to run it */
    /* ------------------------------------------------------------------ */

    public function test_work_waiting_with_no_worker_is_reported(): void
    {
        Queue::fake();

        $this->post(route('leads.analyze', $this->lead()));

        // Long enough that a healthy worker would have picked it up.
        AiJob::query()->update(['queued_at' => now()->subMinute()]);

        $this->assertTrue(app(AiJobQueue::class)->looksStalled());

        $props = $this->get(route('dashboard'))->viewData('page')['props'];

        $this->assertTrue($props['ai_jobs']['stalled']);
        $this->assertStringContainsString('queue:work', $props['ai_jobs']['start_command']);
    }

    public function test_a_worker_that_has_checked_in_recently_is_not_reported_missing(): void
    {
        Queue::fake();

        $this->post(route('leads.analyze', $this->lead()));
        AiJob::query()->update(['queued_at' => now()->subMinute()]);

        app(QueueWorkerStatus::class)->heartbeat();

        $this->assertFalse(app(AiJobQueue::class)->looksStalled());
    }

    public function test_a_worker_busy_on_a_long_job_is_not_reported_missing(): void
    {
        Queue::fake();

        $lead = $this->lead();
        $this->post(route('leads.analyze', $lead));
        $this->post(route('leads.analyze', $this->lead()));

        // A worker inside a five-minute analysis is not polling, so it leaves no
        // heartbeat - and the job behind it has been waiting the whole time.
        // Both signals point at "no worker", and both are wrong. The job in
        // flight is what settles it.
        AiJob::query()->update(['queued_at' => now()->subMinutes(4)]);
        AiJob::query()->latest('id')->firstOrFail()->markRunning();

        $this->assertFalse(app(AiJobQueue::class)->looksStalled());
    }

    public function test_nothing_is_reported_when_nothing_is_waiting(): void
    {
        $this->assertFalse(app(AiJobQueue::class)->looksStalled());
    }

    /* ------------------------------------------------------------------ */
    /* Failure is recorded, not lost */
    /* ------------------------------------------------------------------ */

    public function test_a_worker_that_dies_mid_job_does_not_spin_forever(): void
    {
        Queue::fake();

        $this->post(route('leads.analyze', $this->lead()));

        $job = AiJob::latest('id')->firstOrFail();
        $job->markRunning();

        // Closing the terminal mid-analysis. No exception is thrown, no failed()
        // hook runs - the process is simply gone. Without a sweep the row sits
        // at Running for ever, and the tray shows a spinner for work that
        // stopped days ago, which reads as "still coming".
        $job->update(['started_at' => now()->subHours(3)]);

        app(AiJobQueue::class)->visible();

        $this->assertSame(AiJobStatus::Failed, $job->fresh()->status);
        $this->assertStringContainsString('stopped before this finished', (string) $job->fresh()->error);
    }

    public function test_a_job_whose_subject_was_deleted_says_so_rather_than_crashing(): void
    {
        $lead = $this->lead();

        $job = AiJob::create([
            'type' => AiJobType::LeadAnalysis,
            'status' => AiJobStatus::Queued,
            'label' => 'Analysing Dana Whitfield',
            'subject_type' => $lead->getMorphClass(),
            'subject_id' => $lead->id,
            'queued_at' => now(),
        ]);

        $lead->delete();

        (new RunLeadAnalysis($job->id))->handle();

        $this->assertSame(AiJobStatus::Done, $job->fresh()->status);
        $this->assertStringContainsString('deleted', (string) $job->fresh()->result_summary);
    }

    /* ------------------------------------------------------------------ */
    /* Approval did not move with the work */
    /* ------------------------------------------------------------------ */

    public function test_a_recommendation_rejected_while_queued_stops_the_email(): void
    {
        Queue::fake();

        $lead = $this->lead();
        $recommendation = $this->acceptedRecommendation($lead);

        $this->post(route('leads.emails.generate', $lead), [
            'recommendation_id' => $recommendation->id,
        ]);

        // Approved when you asked, withdrawn before the worker got to it. The
        // rule is that an email is written from a direction you approved, and
        // that has to hold when the writing happens - not when it was requested.
        $recommendation->update(['status' => RecommendationStatus::Rejected]);

        $job = AiJob::latest('id')->firstOrFail();
        (new GenerateEmailDrafts($job->id))->handle();

        $this->assertDatabaseCount('email_generations', 0);
        $this->assertStringContainsString('no longer accepted', (string) $job->fresh()->result_summary);
    }

    /* ------------------------------------------------------------------ */
    /* The tray */
    /* ------------------------------------------------------------------ */

    public function test_a_queued_job_can_be_taken_back_out(): void
    {
        Queue::fake();

        $lead = $this->lead();
        $this->post(route('leads.analyze', $lead));

        $job = AiJob::latest('id')->firstOrFail();

        $this->post(route('ai.jobs.cancel', $job))->assertRedirect();

        $this->assertSame(AiJobStatus::Cancelled, $job->fresh()->status);
    }

    public function test_a_running_job_cannot_be_cancelled(): void
    {
        Queue::fake();

        $this->post(route('leads.analyze', $this->lead()));

        $job = AiJob::latest('id')->firstOrFail();
        $job->markRunning();

        // There is nothing to cancel that would not waste the minutes already
        // spent - the request is in flight either way. Saying so is better than
        // a button that pretends.
        $this->post(route('ai.jobs.cancel', $job))
            ->assertSessionHas('info', fn (string $m) => str_contains($m, 'cannot be stopped'));

        $this->assertSame(AiJobStatus::Running, $job->fresh()->status);
    }

    public function test_a_dismissed_result_leaves_the_tray_but_not_the_database(): void
    {
        Queue::fake();

        $this->post(route('leads.analyze', $this->lead()));

        $job = AiJob::latest('id')->firstOrFail();
        $job->markDone('2 recommendation(s).');

        $this->post(route('ai.jobs.dismiss', $job))->assertRedirect();

        $this->assertSame([], app(AiJobQueue::class)->visible()->all());

        // Kept. What the model was asked to do, and how long it took, is the
        // same kind of history the AI log keeps.
        $this->assertDatabaseCount('ai_jobs', 1);
    }

    public function test_a_running_job_cannot_be_dismissed_out_of_sight(): void
    {
        Queue::fake();

        $this->post(route('leads.analyze', $this->lead()));

        $job = AiJob::latest('id')->firstOrFail();
        $job->markRunning();

        $this->post(route('ai.jobs.dismiss', $job));

        // Hiding work that is still running would leave a lead being analysed
        // with nothing on screen to say so.
        $this->assertNull($job->fresh()->dismissed_at);
        $this->assertCount(1, app(AiJobQueue::class)->visible());
    }

    public function test_the_poll_endpoint_returns_json_not_a_page(): void
    {
        Queue::fake();

        $this->post(route('leads.analyze', $this->lead()));

        $response = $this->getJson(route('ai.jobs.index'))->assertOk();

        $response->assertJsonPath('active', 1);
        $response->assertJsonPath('jobs.0.status', 'Queued');
        $response->assertJsonPath('jobs.0.type', 'lead_analysis');
    }

    /* ------------------------------------------------------------------ */
    /* Progress is an estimate and never claims otherwise */
    /* ------------------------------------------------------------------ */

    public function test_progress_never_reaches_the_end_while_work_continues(): void
    {
        $job = AiJob::create([
            'type' => AiJobType::LeadAnalysis,
            'status' => AiJobStatus::Running,
            'label' => 'Analysing someone',
            'queued_at' => now()->subHour(),
            'started_at' => now()->subHour(),
        ]);

        // An hour into a job that usually takes four minutes. A bar sitting at
        // 100% while the work is still going is a lie told with a straight face.
        $this->assertLessThan(1.0, $job->estimatedProgress());
        $this->assertGreaterThan(0.9, $job->estimatedProgress());
    }

    public function test_the_estimate_learns_how_long_this_machine_actually_takes(): void
    {
        // The number written into the enum was a guess about somebody else's
        // hardware, and the first real run proved it: reading a reply was
        // written down as thirty seconds and took two minutes eighteen on
        // qwen3:4b. A bar driven by the guess would have parked at its ceiling
        // for most of the job.
        foreach (range(1, 4) as $ignored) {
            AiJob::create([
                'type' => AiJobType::ReplyClassification,
                'status' => AiJobStatus::Done,
                'label' => 'Reading a reply',
                'started_at' => now()->subSeconds(140),
                'finished_at' => now(),
            ]);
        }

        $this->assertSame(140, app(AiJobDurations::class)->typicalFor(AiJobType::ReplyClassification));
    }

    public function test_the_written_down_guess_is_used_until_there_is_real_history(): void
    {
        AiJob::create([
            'type' => AiJobType::LeadAnalysis,
            'status' => AiJobStatus::Done,
            'label' => 'Analysing someone',
            'started_at' => now()->subSeconds(10),
            'finished_at' => now(),
        ]);

        // One run is not a measurement.
        $this->assertSame(
            AiJobType::LeadAnalysis->typicalSeconds(),
            app(AiJobDurations::class)->typicalFor(AiJobType::LeadAnalysis),
        );
    }

    public function test_a_job_that_never_reached_the_model_does_not_skew_the_estimate(): void
    {
        // Subject deleted, recommendation withdrawn: finished in milliseconds
        // without generating anything. Counting those would teach the bar that
        // an analysis takes no time at all.
        foreach (range(1, 5) as $ignored) {
            AiJob::create([
                'type' => AiJobType::LeadAnalysis,
                'status' => AiJobStatus::Done,
                'label' => 'Analysing a deleted lead',
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        }

        $this->assertSame(
            AiJobType::LeadAnalysis->typicalSeconds(),
            app(AiJobDurations::class)->typicalFor(AiJobType::LeadAnalysis),
        );
    }

    public function test_a_queued_job_shows_no_progress_at_all(): void
    {
        $job = AiJob::create([
            'type' => AiJobType::LeadAnalysis,
            'status' => AiJobStatus::Queued,
            'label' => 'Analysing someone',
            'queued_at' => now()->subMinutes(10),
        ]);

        // Nothing has happened to it. Ten minutes of waiting is not progress.
        $this->assertSame(0.0, $job->estimatedProgress());
    }
}
