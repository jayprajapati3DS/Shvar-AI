<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Enums\AiRequestType;
use App\Enums\EmailDraftStatus;
use App\Enums\EmailVariant;
use App\Models\EmailDraft;
use App\Models\EmailDraftVersion;
use App\Models\EmailGeneration;
use App\Models\Lead;
use App\Models\LeadProductMatch;
use App\Services\AI\AiResult;
use App\Services\AI\AIServiceInterface;
use App\Services\AI\Exceptions\AiException;
use App\Services\AI\PromptLibrary;
use Illuminate\Support\Facades\DB;

/**
 * Writes personalised outreach emails from an accepted product recommendation.
 *
 *   Lead + Company + Contact + Product + accepted recommendation   (database)
 *        v
 *   EmailContextBuilder                       <- database only, no network
 *        v
 *   PromptLibrary::emailGeneration()
 *        v
 *   AIServiceInterface::generateStructured()  <- Ollama on localhost
 *        v
 *   EmailValidator + EmailQualityChecker
 *        v
 *   email_generations + email_drafts + email_draft_versions
 *
 * Depends on AIServiceInterface, so it has no idea Ollama exists.
 *
 * Three rules it will not break:
 *
 *   1. Nothing is approved on the user's behalf. Every draft is stored as
 *      Draft, and only an explicit human action moves it.
 *   2. Nothing is sent. This class has no reference to EmailServiceInterface at
 *      all - generation and delivery cannot be confused for each other.
 *   3. Nothing from a previous run is modified or deleted. Regenerating inserts
 *      a fresh generation and a fresh set of drafts.
 */
class EmailGenerator
{
    public function __construct(
        private readonly AIServiceInterface $ai,
        private readonly PromptLibrary $prompts,
        private readonly EmailContextBuilder $context,
        private readonly EmailSchema $schema,
        private readonly EmailValidator $validator,
        private readonly EmailQualityChecker $quality,
        private readonly EmailSettings $settings,

        // What this user's own approved emails say about how they write.
        // Contributes nothing until there are enough of them.
        private readonly EmailStyleProfile $style,
    ) {}

    /**
     * The most products one email may pitch.
     *
     * Not arbitrary. Past three the email stops being about anything and starts
     * being a catalogue, which is the failure the whole style guide is arranged
     * against - and the local model's ability to keep several products straight
     * degrades faster than its ability to write one good paragraph.
     */
    public const MAX_PRODUCTS = 3;

    /**
     * Generate one run of three variants.
     *
     * @param  iterable<LeadProductMatch>|LeadProductMatch  $recommendations
     *                                                                        The accepted Phase 3 recommendations to write from, PRIMARY FIRST.
     *                                                                        The email leads with the first and mentions the rest only where the
     *                                                                        company data supports it.
     * @param  string|null  $extraInstructions  Free-text steer for this run only.
     * @param  EmailGeneration|null  $replacing  Set when this is a "Regenerate" of an earlier run.
     *
     * @throws AiException
     */
    public function generate(
        Lead $lead,
        iterable|LeadProductMatch $recommendations,
        ?string $extraInstructions = null,
        ?EmailGeneration $replacing = null,
    ): EmailGeneration {
        $lead->loadMissing('company');

        $set = $this->normalise($recommendations);

        if ($set === []) {
            throw new \InvalidArgumentException('An email needs at least one accepted recommendation.');
        }

        $tone = $this->settings->tone();
        $length = $this->settings->length();

        $prompt = $this->prompts->emailGeneration(
            $this->context->render($lead, $set),
            $tone->instruction(),
            $length->instruction(),
            $this->variantBriefs(),
            $extraInstructions,
            $this->style->promptBlock(),
        );

        // Any AiException propagates to the controller, which turns it into a
        // friendly message. The attempt is already in the Phase 2 log by then.
        $result = $this->ai->generateStructured(
            $prompt,
            $this->schema->schema(),
            AiRequestType::EmailGeneration,
            ['lead_id' => $lead->id],
        );

        $validated = $this->validator->validate($result->data ?? [], $set);

        return $this->persist($lead, $set, $validated, $result, $extraInstructions, $replacing);
    }

    /**
     * Coerce, de-duplicate and cap the recommendation set.
     *
     * Order is preserved because it is meaningful - the first is the primary.
     *
     * @param  iterable<LeadProductMatch>|LeadProductMatch  $recommendations
     * @return list<LeadProductMatch>
     */
    private function normalise(iterable|LeadProductMatch $recommendations): array
    {
        $set = $recommendations instanceof LeadProductMatch
            ? [$recommendations]
            : array_values(iterator_to_array(
                is_array($recommendations) ? new \ArrayIterator($recommendations) : $recommendations
            ));

        $seen = [];
        $unique = [];

        foreach ($set as $recommendation) {
            if (isset($seen[$recommendation->id])) {
                continue;
            }

            $seen[$recommendation->id] = true;
            $recommendation->loadMissing('product');
            $unique[] = $recommendation;
        }

        return array_slice($unique, 0, self::MAX_PRODUCTS);
    }

    /**
     * The per-variant briefs, rendered for the prompt.
     *
     * Read off the enum so adding a variant is a one-line change that reaches
     * the schema, the prompt and the UI at once.
     */
    private function variantBriefs(): string
    {
        $lines = [];

        foreach (EmailVariant::cases() as $variant) {
            $lines[] = sprintf('- style "%s": %s', $variant->value, $variant->brief());
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<LeadProductMatch>  $set
     * @param  array<string, mixed>  $validated
     */
    private function persist(
        Lead $lead,
        array $set,
        array $validated,
        AiResult $result,
        ?string $extraInstructions,
        ?EmailGeneration $replacing,
    ): EmailGeneration {
        return DB::transaction(function () use (
            $lead,
            $set,
            $validated,
            $result,
            $extraInstructions,
            $replacing,
        ): EmailGeneration {
            // The first is the primary: the product the email leads with, the
            // one shown in the drafts list and used by the filters.
            $recommendation = $set[0];

            $missing = $validated['missing_information'];

            // Fall back to the computed gaps when the model says nothing useful.
            if ($missing === []) {
                $missing = $this->context->gaps($lead, $set);
            }

            $generation = EmailGeneration::create([
                'lead_id' => $lead->id,
                'product_id' => $recommendation->product_id,
                'lead_product_match_id' => $recommendation->id,
                'provider' => $result->provider,
                'model' => $result->model,
                'tone' => $this->settings->tone(),
                'length' => $this->settings->length(),
                'extra_instructions' => $extraInstructions,
                'personalization_points' => $validated['personalization_points'],
                'claims_used' => $validated['claims_used'],
                'missing_information' => $missing,
                'warnings' => $validated['warnings'],
                'execution_time_ms' => $result->executionMs,
                'ai_request_id' => $result->logId,
                'regenerated_from_id' => $replacing?->id,
            ]);

            // The full set, primary first, so an email that pitched three
            // products can still say which three a year from now.
            foreach ($set as $position => $item) {
                $generation->recommendations()->attach($item->id, [
                    'is_primary' => $position === 0,
                    'position' => $position,
                ]);
            }

            foreach ($validated['variants'] as $variant) {
                $this->createDraft($generation, $lead, $recommendation, $variant, $result);
            }

            return $generation->load([
                'drafts.product',
                'product',
                'recommendation',
                'recommendations.product',
            ]);
        });
    }

    /**
     * @param  array{variant: EmailVariant, subject: string, body: string, word_count: int}  $variant
     */
    private function createDraft(
        EmailGeneration $generation,
        Lead $lead,
        LeadProductMatch $recommendation,
        array $variant,
        AiResult $result,
    ): EmailDraft {
        $draft = EmailDraft::create([
            'email_generation_id' => $generation->id,
            'lead_id' => $lead->id,
            'product_id' => $recommendation->product_id,
            'lead_product_match_id' => $recommendation->id,

            'variant' => $variant['variant'],

            // Always Draft. The AI never approves its own work.
            'status' => EmailDraftStatus::Draft,

            'subject' => $variant['subject'],
            'body' => $variant['body'],

            // Written once, never updated - the record of what the model wrote
            // before anyone edited it.
            'ai_subject' => $variant['subject'],
            'ai_body' => $variant['body'],

            // Snapshotted so a sent record stays truthful if the lead is
            // later edited or deleted.
            'recipient_email' => $lead->email,
            'recipient_name' => $lead->full_name,

            'personalization_points' => $generation->personalization_points,
            'word_count' => $variant['word_count'],
            'ai_model' => $result->model,
            'ai_request_id' => $result->logId,
            'created_by' => $this->settings->senderName(),
            'version' => 1,
        ]);

        // Version 1 is always the AI's own output.
        EmailDraftVersion::create([
            'email_draft_id' => $draft->id,
            'version' => 1,
            'subject' => $variant['subject'],
            'body' => $variant['body'],
            'source' => EmailDraftVersion::SOURCE_AI,
            'word_count' => $variant['word_count'],
        ]);

        // Stored rather than computed on read, so the list can show a verdict
        // without loading and re-checking every draft.
        $draft->update(['quality' => $this->quality->check($draft)]);

        return $draft;
    }
}
