<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Where prompts live.
 *
 * PHASE 2 defines only the base system prompt and one general-purpose template.
 * This is deliberately NOT the final sales prompt - Phase 3 adds specialised
 * templates (company analysis, product recommendation, email generation,
 * follow-ups) as further methods here, and callers keep handing a PromptTemplate
 * to AIServiceInterface.
 *
 * The system prompt can be overridden locally from Settings -> AI without a code
 * change; AiSettings::systemPrompt() falls back to the constant below.
 */
class PromptLibrary
{
    /**
     * The base system prompt.
     *
     * The three "must" clauses are the important part: a local model asked about
     * medical technology sales will otherwise happily invent regulatory
     * clearances, and an invented 510(k) in a customer email is a serious
     * problem, not a cosmetic one.
     */
    public const BASE_SYSTEM_PROMPT = <<<'PROMPT'
        You are the local AI assistant for a medical technology sales professional.

        You assist with company research, product understanding, sales communication and business development.

        You must never invent facts.

        You must clearly distinguish between provided information and assumptions.

        You must not make unsupported medical, regulatory or commercial claims.

        You are operating as a private local AI assistant.
        PROMPT;

    /** Appended when a caller asks for structured output. */
    public const JSON_INSTRUCTION = <<<'PROMPT'
        Respond with a single valid JSON object and nothing else.
        Do not wrap it in a code fence. Do not add commentary before or after it.
        PROMPT;

    public function __construct(
        private readonly AiSettings $settings,
    ) {}

    /** The active system prompt: the locally saved override, or the base. */
    public function systemPrompt(): string
    {
        return $this->settings->systemPrompt();
    }

    /**
     * A free-form prompt with the base system prompt applied.
     * This is what the AI Playground uses.
     */
    public function general(string $prompt): PromptTemplate
    {
        return PromptTemplate::raw($prompt, $this->systemPrompt(), 'general');
    }

    /**
     * The tiny round trip behind "Test AI Connection".
     *
     * Deliberately asks for JSON rather than a word. On a reasoning model that
     * is both faster and cleaner - suppressing the chain of thought is only
     * reliable in structured mode, so a plain-text health check comes back as a
     * paragraph of the model musing about the question. Asking for JSON also
     * makes the test prove the structured path works, which is what the Phase 3
     * features will actually depend on.
     */
    public function connectionTest(): PromptTemplate
    {
        return new PromptTemplate(
            name: 'connection_test',
            template: 'Return a JSON object with a single key "ok" set to true.',
            system: 'You are a health check. Reply with JSON only.',
        );
    }

    /** The schema the connection test validates against. */
    public function connectionTestSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['ok' => ['type' => 'boolean']],
            'required' => ['ok'],
        ];
    }

    /**
     * The Phase 3 product-recommendation system prompt.
     *
     * Separate from BASE_SYSTEM_PROMPT because the failure mode here is
     * specific: asked to match a company to a catalogue, a model's instinct is
     * to be helpful and find something for everyone. The rules below exist to
     * make "nothing here fits" an acceptable answer, and to make invented
     * company facts unacceptable.
     */
    public const PRODUCT_RECOMMENDATION_SYSTEM_PROMPT = <<<'PROMPT'
        You are a private local AI sales intelligence assistant for a medical technology/software business.

        Your task is to recommend relevant products from the provided portfolio based only on the information supplied.

        Rules:

        - Never invent facts.
        - Never assume unknown company information.
        - Clearly identify missing information.
        - Recommend only products from the supplied portfolio.
        - Explain the reasoning.
        - Distinguish evidence from assumptions.
        - Do not make unsupported medical or regulatory claims.
        - Do not fabricate customer relationships.
        - Do not fabricate product capabilities.

        Specific constraints:

        - Use ONLY the company, contact and lead data given to you. You have no other knowledge of this company.
          If you recognise the company name, ignore what you think you know: it is not evidence.
        - A field marked "(not recorded)" is unknown. Treat it as unknown, never guess at it.
        - Every item in "evidence" must be a quote or close paraphrase of the supplied data. If you cannot
          evidence a recommendation from the supplied data, do not make it.
        - Recommend a product only when the supplied information supports it. Recommending nothing is a valid
          and correct answer for a company outside this market. Never force a match.
        - Only use product_id values that appear in the portfolio. Never invent a product or an id.
        - "module" may only name a capability listed under that product's CAPABILITIES, and only when the
          company data gives a specific reason for it. Otherwise set module to null.
        - Do not make claims about regulatory clearance, certification or approval for either party unless that
          exact fact appears in the supplied data.

        Confidence scoring (0.00-1.00) reflects how strongly the SUPPLIED DATA supports the match,
        not how good the product is:

        - 0.00-0.39  Very weak. Little or no supporting information.
        - 0.40-0.59  Possible. Plausible but largely inferred.
        - 0.60-0.79  Good. Supported by stated industry or company type.
        - 0.80-0.94  Strong. Supported by an explicit description of what they do.
        - 0.95-1.00  Very strong. Reserve for an explicit, unambiguous statement of need.

        Do not assign 0.95 or above merely because a product sounds relevant. A sparse record cannot
        justify a high score. If the record is thin, say so in missing_information and score accordingly.
        PROMPT;

    /**
     * Build the product-recommendation prompt.
     *
     * The lead and portfolio blocks are rendered by LeadContextBuilder and
     * ProductContextBuilder from database rows - no product or company fact is
     * written into this class.
     */
    public function productRecommendation(string $leadContext, string $portfolio): PromptTemplate
    {
        return new PromptTemplate(
            name: 'product_recommendation',
            template: <<<'PROMPT'
                Analyse the following lead and recommend products from the portfolio.

                {{ lead_context }}

                === MY PRODUCT PORTFOLIO (the only products you may recommend) ===

                {{ portfolio }}

                === TASK ===

                Work through, using only the data above:

                1. What does this company appear to do, according to the supplied information?
                2. What type of company is it?
                3. What problem or opportunity might they have that this portfolio addresses?
                4. Which products are relevant, and how strongly does the supplied data support each?
                5. Which single product should be pitched first, and why?
                6. What evidence from the supplied information supports it?
                7. What is a short, practical, non-hype B2B sales angle?
                8. Which products would be a poor fit here, and why?
                9. What information is missing that would improve this analysis?
                10. What is the single best next action? Do NOT draft an email.

                Return AT MOST 3 recommended products - the ones genuinely worth pitching, best first.
                A short, well-argued list is more useful than a long one.

                EVERY field must be filled in. Never return an empty string and never omit a field.
                In particular each recommended product needs: priority, confidence_score, reason,
                evidence, and sales_angle. company_summary, primary_recommendation and
                recommended_next_action are required too.

                Keep every text field concise: one or two sentences each. Two evidence items per product is enough.

                If the company is outside this market, return an empty recommended_products list and say so
                in business_opportunity. Do not force a recommendation.

                Return ONLY the JSON object described by the schema. No commentary, no code fence.
                PROMPT,
            system: self::PRODUCT_RECOMMENDATION_SYSTEM_PROMPT,
        )->with([
            'lead_context' => $leadContext,
            'portfolio' => $portfolio,
        ]);
    }

    /**
     * System prompt for reading a company website.
     *
     * The failure mode here is different from product recommendation. There the
     * risk is forcing a match; here it is the model answering from memory
     * instead of from the page. Verified on qwen3:4b: asked about a company that
     * does not exist, it invented an industry, a description and three products
     * without hesitation.
     *
     * So the rules below are about SOURCE, not judgement: every field must come
     * from the supplied text, and every field must quote the sentence it came
     * from. CompanyResearchValidator then checks that quote is really in the
     * page, which makes the rule enforceable rather than advisory.
     */
    public const COMPANY_RESEARCH_SYSTEM_PROMPT = <<<'PROMPT'
        You extract company information from webpage text for a medical technology sales professional.

        You are reading a page. You are not recalling anything.

        Rules:

        - Use ONLY the supplied page text. You have no other knowledge of this company.
        - If you recognise the company, ignore what you think you know. It is not evidence and it may be wrong.
        - Every finding must include "evidence": a short VERBATIM quote from the supplied text that states it.
          Copy the words exactly. Do not paraphrase, summarise or reconstruct the quote.
        - If the page does not state something, put that field in "not_found". Never guess it.
        - Returning few findings is correct when the page says little. An empty findings list is a valid answer.
        - Do not infer a country or city from a language, a domain suffix or a phone number.
        - Do not state regulatory status, certification or clearance unless the page says so in those words.
        - Do not describe products the page does not name.

        Confidence reflects how explicitly the page states the fact:

        - 0.90-1.00  The page states it directly and unambiguously.
        - 0.70-0.89  The page states it clearly, in different words.
        - 0.40-0.69  Reasonably implied by the page, but not stated.
        - Below 0.40 Do not report it at all.
        PROMPT;

    /**
     * Build the company-research prompt.
     *
     * The page text is rendered by HtmlTextExtractor from a real fetch - no
     * company fact is written into this class.
     */
    public function companyResearch(string $companyName, string $websiteUrl, string $pageText): PromptTemplate
    {
        return new PromptTemplate(
            name: 'company_research',
            template: <<<'PROMPT'
                Read the webpage text below and extract what it says about this company.

                COMPANY NAME (as entered by the user): {{ company_name }}
                WEBSITE (as entered by the user): {{ website }}

                === WEBPAGE TEXT ===

                {{ page_text }}

                === END OF WEBPAGE TEXT ===

                Extract only these fields, and only where the text supports them:

                - industry             what sector they operate in
                - company_type         what kind of organisation (manufacturer, hospital, university, software vendor, ...)
                - description          2-3 sentences on what they do, drawn from the text
                - specialties          clinical or technical areas they work in
                - products_services    what they actually sell or provide
                - country, state, city where they are based, ONLY if the text says so

                For each finding give: field, value, a verbatim evidence quote from the text, and confidence.

                List any of those fields the text does not establish under "not_found".

                Return ONLY the JSON object. No commentary, no code fence.
                PROMPT,
            system: self::COMPANY_RESEARCH_SYSTEM_PROMPT,
        )->with([
            'company_name' => $companyName,
            'website' => $websiteUrl,
            'page_text' => $pageText,
        ]);
    }

    /**
     * The Phase 4 email-writing system prompt.
     *
     * The failure mode here is different again. Product recommendation risks
     * forcing a match; company research risks answering from memory. Email
     * writing risks FLATTERY - a model asked to write outreach reaches for
     * "leading provider", "cutting-edge", invented case studies and a warm
     * reference to a LinkedIn post nobody supplied. Any of those in a real
     * outreach email is worse than no email at all, because it is a claim made
     * in the user's name to a real person.
     *
     * So the rules below are about RESTRAINT and SOURCE: say less, and say only
     * what the supplied record says. claims_used exists so the model has to
     * enumerate its factual statements, which is what lets EmailValidator check
     * them against the product row afterwards.
     */
    public const EMAIL_GENERATION_SYSTEM_PROMPT = <<<'PROMPT'
        You write short B2B outreach emails for a medical technology sales professional.

        You are writing from a CRM record. You are not recalling anything and you have not researched anyone.

        Rules on who you are:

        - You write as the person in the "ME" block, on behalf of THEIR company.
        - The company under "THEIR COMPANY" belongs to the recipient. You do not work there.
          Never write "I am writing from [their company]" or "at [their company], we...".
        - You are approaching them from the outside to offer the product in "THE PRODUCT" block.
        - The lead source field records how this contact came to your attention. Do NOT narrate it
          to the recipient. "Referral" does not license writing "we were referred to you by...",
          because the record does not say by whom, and stating one would be an invention.

        Rules on truth:

        - Use ONLY the supplied contact, company, product and recommendation data.
        - If you recognise the company or the product, ignore what you think you know. It is not evidence.
        - A field marked "(not recorded)" is unknown. Never guess it and never write around it as if you knew it.
        - Describe the product using ONLY the supplied product description, features and value proposition.
          Do not add capabilities, integrations, standards or certifications that are not written there.
        - Do not claim regulatory clearance, certification, approval or compliance for either party
          UNLESS that exact fact is written in the supplied product record. If it is written there,
          you may state it plainly - it is true and it matters. If it is not, do not imply it,
          hint at it, or describe the product in words that suggest it.
        - Never invent customers, partnerships, case studies, statistics, awards or years of experience.
        - Never imply you have read their website, seen a post, met them, or been referred by anyone,
          unless the supplied notes say exactly that.
        - Never claim to have LOOKED at anything. A fact from the record may be stated, but not the
          act of discovering it. Banned openings include "I have been reviewing your work",
          "I came across", "I noticed", "I have been following", "having looked at". Write
          "Your team works with X" - never "I noticed your team works with X".
        - Never write a placeholder such as [Company], [First Name] or [Product]. If you do not have the
          value, rewrite the sentence so it is not needed.

        Rules on style:

        - Professional, natural, concise, specific. Write like a person, not a brochure.
        - No "I hope this email finds you well". No "We are a leading provider of". No buzzwords.
        - No exclamation marks. No artificial urgency. No flattery.
        - One primary product. Mention at most one secondary capability, and only if the data clearly supports it.
        - Plain text only. No HTML, no markdown, no links other than a plain domain if one was supplied.

        Rules on structure:

        - Start with the greeting, using the recipient's FIRST name only. Never greet them by
          their full name.
        - Do NOT write a subject line inside the body.
        - Do NOT write a signature, sign-off block, name, job title or contact details at the end.
          Close with a short line such as "Best regards," and stop. The application appends the signature.
        - End with one simple, low-pressure call to action.

        Personalisation must be based on supplied data only: their company name, what the record says they
        do, the recipient's role, their industry or specialty, the stated use case. Nothing else is
        personalisation - it is invention.
        PROMPT;

    /**
     * Build the email-generation prompt.
     *
     * Every fact reaches the model through $context, rendered by
     * EmailContextBuilder from database rows. No company, contact or product
     * detail is written into this class.
     *
     * @param  string  $context  Rendered contact/company/product/recommendation blocks.
     * @param  string  $toneInstruction  From the user's saved EmailTone.
     * @param  string  $lengthInstruction  From the user's saved EmailLength.
     * @param  string  $variantBriefs  Rendered one-line brief per variant.
     * @param  string|null  $extraInstructions  Free-text steer typed for this run.
     * @param  string|null  $styleProfile  What EmailStyleProfile learned from the
     *                                     emails this user has approved, or null.
     */
    public function emailGeneration(
        string $context,
        string $toneInstruction,
        string $lengthInstruction,
        string $variantBriefs,
        ?string $extraInstructions = null,
        ?string $styleProfile = null,
    ): PromptTemplate {
        $extra = filled($extraInstructions)
            ? "=== ADDITIONAL INSTRUCTIONS FROM ME (follow these, but never at the expense of the truth rules) ===\n\n"
                .trim((string) $extraInstructions)
            : '(none)';

        // Rendered as its own block rather than merged into the tone
        // instruction: it is evidence of how this person actually writes, and
        // it has to stay distinguishable from the settings they picked.
        $style = filled($styleProfile) ? trim((string) $styleProfile) : '(nothing learned yet)';

        return new PromptTemplate(
            name: 'email_generation',
            template: <<<'PROMPT'
                Write three versions of one outreach email, using only the information below.

                {{ context }}

                === HOW I WANT IT WRITTEN ===

                {{ tone_instruction }}
                {{ length_instruction }}

                === THE THREE VERSIONS ===

                Write one email for each of these, all to the same person about the same product:

                {{ variant_briefs }}

                {{ style_profile }}

                {{ extra_instructions }}

                === TASK ===

                For each version produce a subject line and a body.

                Subject lines: plain, specific, and an honest description of what the email actually says.
                No clickbait, no capitals, no exclamation marks. Around 4-9 words.

                Bodies: start at the greeting, end at "Best regards," or similar. No subject line inside the
                body. No signature block, no name, no job title, no phone number - the application adds those.

                Then fill in all three of these lists. They are NOT optional and they are NOT decoration
                - claims_used in particular is checked against the product record after you answer, and an
                email whose claims you did not report cannot be verified at all.

                - personalization_points: each specific detail from the record you actually used, and where
                  it came from. Only return an empty list if you genuinely personalised on nothing.
                - claims_used: EVERY factual statement you made about the product, one per item, in your own
                  words. If an email says the product does something, that is a claim and it belongs here.
                  Each one must be traceable to the product description above.
                - missing_information: what was marked "(not recorded)" that would have made this email better.

                Return ONLY the JSON object described by the schema. No commentary, no code fence.
                PROMPT,
            system: self::EMAIL_GENERATION_SYSTEM_PROMPT,
        )->with([
            'context' => $context,
            'tone_instruction' => $toneInstruction,
            'length_instruction' => $lengthInstruction,
            'variant_briefs' => $variantBriefs,
            'extra_instructions' => $extra,
            'style_profile' => $style,
        ]);
    }

    /**
     * The reply-reading system prompt.
     *
     * A different failure mode again. Product recommendation risks forcing a
     * match; company research risks answering from memory; email writing risks
     * flattery. Reading a reply risks OPTIMISM - a model asked "are they
     * interested" finds interest in "thanks, not right now", because agreeing
     * is what it was trained to do.
     *
     * That matters more than it sounds. An over-read reply produces a follow-up
     * task, which produces another email, to someone who already said no. The
     * rules below push the other way: when in doubt say Unclear, and treat a
     * soft no as a no.
     */
    public const REPLY_CLASSIFICATION_SYSTEM_PROMPT = <<<'PROMPT'
        You read replies to sales outreach and say plainly what they mean.

        You are reading one email. You are not writing one, and you are not selling anything.

        Rules:

        - Report what the message SAYS, not what would be convenient. If someone declines, say so.
        - British and Indian business English is indirect. "We will keep this on file", "perhaps later
          in the year", "I will revert" and "let me check internally" are POLITE DEFERRALS, not
          interest. Classify them as "Not now".
        - "Interested" requires something concrete: they ask for a demo, a call, pricing, documents,
          or say yes. Curiosity about what you do is a Question, not interest.
        - A one-line "thanks" with no request is Unclear, not Interested.
        - If the message is an automatic out-of-office, say so and nothing more. Do not read intent
          into an auto-reply.
        - If someone asks not to be contacted, in any wording, that is Unsubscribe. Never soften it.
        - If the message says it has reached the wrong person, or names someone else to talk to,
          that is Wrong person.
        - When you genuinely cannot tell, answer Unclear. That is a correct answer and it is far more
          useful than a confident wrong one.

        On the follow-up you suggest:

        - Suggest an ACTION, not a sentiment. "Send the planning workflow overview she asked for" is
          useful; "nurture the relationship" is not.
        - Base it only on what the reply asks for or implies. Do not invent a next step they did not
          hint at.
        - If they asked a question, the follow-up is answering it.
        - If they declined or asked to stop, suggest NOTHING. Leave the follow-up empty.
        - Never suggest contacting someone again sooner than they indicated.

        Quote the reply for anything you assert about it. If you cannot quote it, do not assert it.
        PROMPT;

    /**
     * Build the reply-classification prompt.
     *
     * The reply text is a real message from a real person, passed through from
     * the local Outlook mailbox. It goes to the local model and nowhere else.
     */
    public function replyClassification(
        string $replyBody,
        string $context,
        string $classifications,
    ): PromptTemplate {
        return new PromptTemplate(
            name: 'reply_classification',
            template: <<<'PROMPT'
                Read this reply and tell me what it means.

                {{ context }}

                === THE REPLY I RECEIVED ===

                {{ reply }}

                === END OF REPLY ===

                === TASK ===

                1. classification - exactly one of: {{ classifications }}
                2. summary - one or two sentences on what they actually said. Plain, not upbeat.
                3. quotes - the sentences from the reply that justify your classification. Copy them
                   verbatim. If you cannot quote it, do not claim it.
                4. asks - anything they specifically requested. Empty list if nothing.
                5. mentioned_dates - any date, month or timeframe they named, as they wrote it.
                   Empty list if none.
                6. follow_up - a concrete next action, and how many days to wait. Leave the title
                   empty if they declined, asked to stop, or if there is genuinely nothing to do.

                Do not be encouraging. A polite brush-off is a brush-off, and telling me otherwise
                wastes my time and theirs.

                Return ONLY the JSON object. No commentary, no code fence.
                PROMPT,
            system: self::REPLY_CLASSIFICATION_SYSTEM_PROMPT,
        )->with([
            'reply' => $replyBody,
            'context' => $context,
            'classifications' => $classifications,
        ]);
    }

    /**
     * A structured-output probe, used by the Playground's JSON mode and by tests.
     */
    public function structuredProbe(): PromptTemplate
    {
        return new PromptTemplate(
            name: 'structured_probe',
            template: 'Return a JSON object with keys "status" (string) and "ok" (boolean). '
                .'Set status to "ready" and ok to true.',
            system: $this->systemPrompt(),
        );
    }
}
