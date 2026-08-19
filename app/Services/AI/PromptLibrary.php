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
