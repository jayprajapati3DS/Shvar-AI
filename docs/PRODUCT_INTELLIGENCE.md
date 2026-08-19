# Product Intelligence

How Shvar AI Copilot turns a lead into product recommendations, using a model running on your own
machine.

> **The AI recommendation engine is an assistive sales tool. Recommendations must be reviewed by the
> user before being acted upon.**
>
> Nothing it produces reaches your pipeline on its own. Every recommendation is stored awaiting your
> decision, and only you can accept one.

---

## 1. What it does

On a lead, **Analyze Lead** sends the stored company, contact and lead information — together with
your product portfolio — to the local model, and asks a specific question: *which of these products
does this record actually support recommending, and what is the evidence?*

```
Lead + Company + Contact (your database)
        |
        v
LeadContextBuilder          renders the record, marking blanks "(not recorded)"
ProductContextBuilder       renders the ACTIVE portfolio from the products table
        |
        v
PromptLibrary::productRecommendation()
        |
        v
AIServiceInterface::generateStructured()   ->  Ollama on localhost  ->  local LLM
        |
        v
RecommendationValidator     drops invented products, coerces fields
ConfidenceCalibrator        caps confidence at what the record supports
        |
        v
lead_analyses  +  lead_product_matches      (status: Suggested)
        |
        v
Your review: Accept / Reject
```

Nothing leaves the machine. See [LOCAL_AI.md](LOCAL_AI.md) for the privacy architecture.

## 2. How product matching works

There is no rules engine and no hard-coded mapping. Matching is done by the model, from two inputs:

**The lead context** — company name, website (as stored text, never visited), country, industry,
company type, description, specialties, what they sell, notes; the contact's job title and
department; the lead's source, status, priority and notes.

**The portfolio** — every ACTIVE product, rendered from the `products` table:

```
PRODUCT_ID: 1
NAME: 3dsurgical Platform
CATEGORY: Medical Software / Surgical Planning
DESCRIPTION: A digital medical platform for case management, 3D visualization...
CAPABILITIES:
  - 3D Viewer
  - Fibula Flap Planning
  - Orthognathic Planning
  - Hip Planning
  - Knee Planning
  ...
TARGET CUSTOMERS:
  - Medical device companies
  - PSI companies
  ...
TARGET SPECIALTIES:
  - Orthopedics
  - Maxillofacial surgery
VALUE PROPOSITION: One platform covering case management...
```

Three deliberate decisions about that block:

- **The database is the single source of truth.** No product fact is written into application code.
  Edit a product in the UI and the next analysis sees the change immediately.
- **Inactive products are never sent.** A retired product cannot be recommended if it is never
  mentioned.
- **`sales_notes` is never sent.** Those are private positioning reminders written for you; feeding
  them in makes the model parrot them back as though they were findings about the company.
- **`detailed_description` is not sent either.** Across seven products it dominated the prompt and
  timed the model out on CPU, without improving matches — the short description, capabilities and
  targets carry the signal.

### Planning modules

The 3dsurgical Platform contains capabilities rather than separate catalogue entries:

```
3dsurgical Platform
  ├── 3D Viewer
  ├── Fibula Flap Planning
  ├── Orthognathic Planning
  ├── Hip Planning
  ├── Knee Planning
  ├── Shoulder Planning
  └── Custom modules
```

A recommendation may name one of these in its `module` field — but only if the company data gives a
specific reason (a knee implant manufacturer → Knee Planning). Two guards:

1. The prompt states that a module needs a reason, and must otherwise be null.
2. `RecommendationValidator` checks the named module against **that product's own `key_features`**.
   A module the product does not list is dropped and recorded as a warning.

So the module list is never hard-coded, and cannot be invented.

## 3. AI architecture

Phase 3 adds no new AI transport. It uses the Phase 2 layer unchanged:

| Class | Role |
| --- | --- |
| `Recommendation\AiProductMatcher` | Orchestrates: build context → ask model → validate → persist |
| `Recommendation\LeadContextBuilder` | Renders the lead; also scores how complete the record is |
| `Recommendation\ProductContextBuilder` | Renders the active portfolio from the database |
| `Recommendation\RecommendationSchema` | The JSON Schema the model is constrained to |
| `Recommendation\RecommendationValidator` | Refuses to trust the output |
| `Recommendation\ConfidenceCalibrator` | Caps confidence at what the evidence supports |
| `PromptLibrary::productRecommendation()` | The prompt |

`AiProductMatcher` implements the `App\Contracts\Ai\ProductMatcher` interface declared back in
Phase 1, and depends on `AIServiceInterface` — so it has no idea Ollama exists, and swapping the
local runtime does not touch it.

## 4. Prompt architecture

No prompt lives in a controller. The system prompt is a specialisation of the Phase 2 base, aimed at
one specific failure: asked to match a company against a catalogue, a model's instinct is to be
helpful and find something for everyone.

Key clauses:

- Use ONLY the supplied data. If you recognise the company name, ignore what you think you know.
- A field marked "(not recorded)" is unknown. Never guess at it.
- Every evidence item must be a quote or close paraphrase of the supplied data.
- **Recommending nothing is a valid and correct answer.** Never force a match.
- Only use product_id values from the portfolio.
- A module must be one of that product's listed capabilities.
- No claims about regulatory clearance unless that exact fact is in the supplied data.

That last one matters in this domain: an invented 510(k) in a sales conversation is a serious
problem, not a cosmetic one.

### Why blanks are shown rather than omitted

`LeadContextBuilder` prints empty fields as `(not recorded)` instead of leaving them out. A model
shown nothing tends to fill the gap with something plausible; a model shown an explicit
"not recorded" reliably reports it under `missing_information` instead.

## 5. Confidence score

`0.00`–`1.00`, meaning **how strongly the stored data supports this match** — not how good the
product is.

| Range | Meaning |
| --- | --- |
| 0.00–0.39 | Very weak match |
| 0.40–0.59 | Possible match |
| 0.60–0.79 | Good match |
| 0.80–0.94 | Strong match |
| 0.95–1.00 | Very strong match |

### Calibration

Models are poor at this. Given a record containing only "Acme Medical, Germany", a helpful model
still says 0.9 — because it is scoring the *product*, not the *evidence*.

So the score is also capped deterministically, from how complete the record actually is:

| Record completeness | Confidence ceiling |
| --- | --- |
| Rich — description, industry, type, what they sell | 1.00 |
| Good — most of the substance | 0.90 |
| Partial — industry or type, little else | 0.75 |
| Thin — barely more than a name | 0.60 |
| Empty | 0.40 |

Completeness is weighted: description and products/services count for most, industry and company
type next, contact role and country least — because those are what a recommendation can genuinely be
argued from.

**Calibration only ever lowers a score, never raises one.** When it fires, the model's original
number is kept in `raw_confidence_score` and the UI says so:

> Model said 97%; lowered because the stored record is thin.

Displaying a different number than the model produced, without saying so, would be dishonest — and
the gap is itself useful: it means fill in the company record.

## 6. Human approval

Two independent axes on every recommendation:

| Field | Meaning | Values |
| --- | --- | --- |
| `recommendation_type` | where it came from | Manual · AI Primary · AI Secondary |
| `status` | what you decided | Suggested · Accepted · Rejected · Archived |

Rules the application will not break:

- **Every AI recommendation is created `Suggested`.** The AI never accepts its own work.
- **Analysis changes nothing else.** Not lead status, not priority, not stage. A test asserts this.
- **Accepting is an explicit action** and records an activity on the timeline, so the audit trail
  shows a human made the call and when.
- **Rejected recommendations are kept, not deleted** — useful history, and it stops the same idea
  being re-litigated after the next run.
- **Manual selection always works.** A hand-picked product is `Manual` and starts `Accepted`;
  choosing it *is* the decision. AI output never blocks it — and a previously rejected suggestion
  does not prevent you adding that product deliberately.

## 7. Recommendation history

A lead can be analysed any number of times. **Every run is kept.**

Each creates a `lead_analyses` row (the company reading, opportunity, products to avoid, missing
information, next action, model used, duration) with its recommendations hanging off it in
`lead_product_matches`.

Re-running is additive. Nothing from a previous analysis is modified or deleted, so the reasoning
behind an old recommendation stays inspectable — useful when a deal comes back six months later.

> This required dropping the original `unique(lead_id, product_id)` constraint from Phase 1. It was
> correct when matches were hand-made, and wrong once history existed: two runs will very often
> recommend the same product, and the second insert would have failed, silently losing the newer
> reasoning.

**History** on the lead page lists past runs; clicking one loads it in full (fetched on demand, so a
lead analysed a dozen times does not bloat the page).

## 8. What the validator catches

The schema constrains shape; the validator constrains meaning. Every case below is something a model
actually does:

| Model behaviour | What happens |
| --- | --- |
| References a product_id not in the catalogue | Dropped, recorded as a warning |
| Names a real product with the wrong id | Recovered by exact name match |
| Names a product that does not exist | Dropped — never fuzzily guessed |
| Recommends the same product twice | First kept, rest dropped |
| Returns confidence as `92`, `"0.92"`, `"high"` | Coerced to 0.92 / 0.92 / 0.0 |
| Returns confidence of 7 or −3 | Clamped to 0–1 |
| Names a module the product lacks | Module dropped, recorded as a warning |
| Names a primary it did not recommend | Falls back to the highest-ranked, records a warning |
| Returns `"null"` as a string | Treated as empty |
| Returns nothing usable | `InvalidAiResponseException`; **no analysis is stored** |

Warnings are shown on the analysis, so the filtering is visible rather than silent.

## 9. Examples

### Patient-specific implant manufacturer

Stored: *"Develops patient-specific cranial and mandibular implants… surgeon review is currently done
over email with screenshots, which they described as their biggest bottleneck."*

| Product | Priority | Confidence |
| --- | --- | --- |
| 3dsurgical Platform | High | 85% |
| Pre-operative Planning & 3D Design Services | Medium | 65% |
| MySegmenter | Low | 55% |

Evidence quoted the stored note about email-based review. Sales angle: *"Replace email-based surgeon
reviews with a single, reviewable digital workflow for patient-specific implants."*

### A company outside the market

A logistics software vendor should produce **no recommendation**. The prompt makes that an explicit,
correct outcome, and the UI states it plainly:

> The model found nothing in your portfolio that the stored information supports recommending. That
> is a valid result, not a failure.

## 10. Limitations

1. **It is a suggestion engine, not a decision engine.** Review everything.
2. **It only knows what you have recorded.** A thin company record produces a thin analysis — hence
   `missing_information` and confidence capping. The fix is better CRM data, not a better prompt.
3. **The website is never visited.** Only the stored URL string is passed. No scraping (Phase 4+).
4. **Small models under-fill large schemas.** A 4B model sometimes leaves fields blank; the validator
   substitutes sane defaults, but a larger model gives noticeably fuller reasoning.
5. **Speed.** See below — this is the main practical constraint on CPU-only hardware.
6. **No semantic search.** The whole portfolio is sent every time. Fine for seven products; a
   catalogue of hundreds would need retrieval first.
7. **Analysis is synchronous.** One request blocks until it finishes. There is no queue.
8. **The lead page probes the model** on load, to decide whether the Analyze button can be enabled.
   That adds roughly a quarter-second, and is why leads/show is excluded from the "makes no HTTP
   request" test.

### Performance

Profiled on an i7-9750H, CPU-only (see [LOCAL_AI.md](LOCAL_AI.md) on why the GPU is unavailable),
with qwen3:4b:

| Phase | Tokens | Time |
| --- | --- | --- |
| Prompt evaluation | ~2,400 | ~100s |
| Generation | ~700 | ~170s |
| **Total** | | **~4–5 minutes** |

Generation runs at roughly 3 tok/s at this context size, versus 7 tok/s on a short prompt — a bigger
context makes every generated token more expensive on CPU.

`AI_TIMEOUT` is therefore set to 600s. To make this faster, in order of impact: get the GPU working,
use a smaller model, or trim the portfolio.

## 11. What Phase 3 does not do

No email generation, no subject lines, no outreach drafting, no Gmail or Outlook, no sending. The
`recommended_next_action` field is a suggestion in words — *"Ask whether they currently perform
segmentation internally"* — never a drafted message.

Those are Phase 4.
