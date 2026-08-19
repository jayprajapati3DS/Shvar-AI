# AI Personalized Sales Email Generation

Phase 4 of Shvar AI Copilot. The local model writes outreach emails from what the
CRM already knows, and a human decides whether any of them are worth sending.

**The one rule everything else is arranged around: nothing is ever sent without an
explicit human approval, and in this phase nothing is sent at all.**

---

## 1. The workflow

```
Lead
  │
  ├── Company + Contact information            (Phase 1, your data)
  ├── Accepted product recommendation          (Phase 3, you accepted it)
  └── Sales angle
        │
        ▼
  Generate personalized email                  ← you click this
        │
        ▼
  Local model writes THREE variants            ← Ollama on localhost
        │
        ▼
  Draft  ──edit──▶  User Edited
        │                │
        └────────────────┴──▶  Approve  ──▶  Approved
                                                 │
                                                 ▼
                                          Send (simulated)  ──▶  Sent
```

Two things cannot happen, by construction:

- **Generation cannot send.** `EmailGenerator` holds no reference to
  `EmailServiceInterface`. The two never meet.
- **An unapproved draft cannot be sent.** Checked in three places — the
  controller, `EmailDraftEditor::send()`, and inside `LocalTestEmailService`
  itself, which throws `EmailNotApprovedException` rather than trusting its
  caller.

---

## 2. Privacy architecture

Identical to Phases 2 and 3. Nothing changed, and nothing was added.

```
Laravel  ──▶  AIServiceInterface  ──▶  OllamaAIService  ──▶  http://localhost:11434
                                                                    │
                                                              Local LLM
```

- No OpenAI, Anthropic, Gemini, Azure OpenAI, or any other cloud LLM.
- No external email service. No Gmail, no Microsoft Graph, no SMTP, no SendGrid.
- No outbound HTTP anywhere in `app/Services/Email` — asserted by a test that
  greps the directory.
- Company, contact, product and email content never leave the machine.

`tests/Feature/Email/EmailPrivacyTest.php` asserts all of this against the source
tree rather than against behaviour, so a future change that introduces a cloud
provider fails the suite instead of quietly working.

---

## 3. Prompt architecture

### What the model is given

`EmailContextBuilder` renders five blocks, all from database rows:

| Block | Source |
| --- | --- |
| `RECIPIENT` | the contact — name, job title, department, country, notes |
| `THEIR COMPANY` | the company — industry, type, location, description, what they sell, specialties, notes |
| `HOW THIS LEAD AROSE` | the lead — source, status, notes |
| `THE PRODUCT I AM WRITING ABOUT` | the product row, complete |
| `THE APPROVED SALES DIRECTION` | the accepted `LeadProductMatch` — reason, sales angle, use case, evidence, confidence |

Empty fields are printed as `(not recorded)` rather than omitted. This matters
more than it looks: a model shown a field marked not-recorded reports it under
`missing_information`, whereas a model shown nothing fills the gap with something
plausible.

The product block is prefixed with *"This is the complete and only description of
this product you may use"* — §28 of the brief. The products table is the source of
truth, and whatever the model absorbed about a similarly-named product during
pretraining is not evidence.

Private `sales_notes` are included but explicitly marked *do not quote these to
the recipient*.

### The system prompt

`PromptLibrary::EMAIL_GENERATION_SYSTEM_PROMPT`.

Each phase has a different failure mode, and each system prompt targets its own:

| Phase | What the model wants to do wrong |
| --- | --- |
| 3 — recommendation | force a match, because being helpful means finding something for everyone |
| Company research | answer from memory instead of from the page |
| **4 — email** | **flatter** — "leading provider", "cutting-edge", an invented case study, a warm reference to a LinkedIn post nobody supplied |

So the Phase 4 rules are about restraint and source. Say less; say only what the
record says. Explicitly banned: inventing customers, partnerships, case studies,
statistics, awards, certifications or regulatory clearance; implying you have read
their website or been referred; writing a placeholder; writing a signature.

### The task prompt

Asks for three variants, then three self-report arrays:

- `personalization_points` — which specific details from the record were used
- `claims_used` — every factual statement made about the product, one per item
- `missing_information` — what was `(not recorded)` and would have helped

`claims_used` is the mechanism behind §27. A claim that is not traceable to the
product record is a fabrication, and the model has to enumerate its claims for
that to be checkable at all.

### Tone and length

From Settings → Email, stored per-user and passed into every prompt.

| Tone | Length | Word band |
| --- | --- | --- |
| Professional *(default)* | Short | 60–110 |
| Consultative | Standard *(default)* | 100–180 |
| Technical | Detailed | 160–260 |
| Executive | | |
| Friendly | | |

The length band is also what the quality check measures against, so the setting
you chose is the number the panel judges by. Whatever was in force at generation
time is stored on the run — changing your default tone does not rewrite history.

### Additional instructions

A free-text field on the generate dialog, e.g. *"Focus on segmentation. Do not
mention the platform."* Capped at 1000 characters: it is concatenated into the
prompt, and an enormous instruction block would crowd out the company and product
data the email has to be built from. It is labelled in the prompt as coming from
you, and explicitly subordinate to the truth rules.

---

## 4. The three variants

| Style | Brief |
| --- | --- |
| `professional_direct` | State plainly who you are, why you are writing, what you are offering. No preamble. |
| `consultative` | Lead with the workflow problem their situation suggests, then connect the product to it. |
| `executive_short` | Under 90 words. One idea, one sentence of relevance, one clear ask. |

Defined on `EmailVariant`, which drives the JSON schema's allowed values, the
prompt briefs, and the UI labels at once. A style the model invents fails to
resolve and the variant is dropped.

---

## 5. Structured output and validation

The schema is passed to Ollama's `format` parameter, which *constrains
generation* rather than merely requesting a shape. That is the first line of
defence. `EmailValidator` is the second, because a constrained model still
happily emits a structurally perfect object containing `[Company]` and a claim
the product record never made.

What the validator does:

| Problem | Response |
| --- | --- |
| HTML or markup anywhere | stripped (twice — decoding can reveal an entity-encoded tag) |
| `[First Name]`, `{{company}}`, `<COMPANY>` | **variant dropped** |
| A signature block the model wrote itself | removed, sign-off line kept |
| A `Subject:` line inside the body | removed |
| An unrecognised variant style | variant dropped |
| A duplicate variant style | dropped |
| Empty subject or body | variant dropped |
| A banned phrase ("I hope this email finds you well") | **flagged, not removed** |
| A claim whose words mostly do not appear in the product record | **flagged, not removed** |

The last two are flagged rather than removed on purpose: silently cutting a
sentence would leave a gap the user cannot see. Flagging puts it in front of them.

If no variant survives, that is a failed generation — `InvalidAiResponseException`
— not a draft with an empty body. Nothing is written to the database.

Everything removed or doubted is stored in `email_generations.warnings` and shown
on the draft page under **"The output was filtered"**, so the UI never pretends
the output arrived clean.

### Unsupported claim detection

`EmailValidator::unsupportedClaims()` compares each entry in `claims_used`
against the concatenated product record (name, category, both descriptions, key
features, target customer, target specialty, value proposition) plus the accepted
recommendation's reason, sales angle, use case and module.

The comparison is word-overlap on significant words — four characters or more,
stopwords removed — with a 50% threshold. Deliberately generous: the model is
asked to paraphrase, and demanding verbatim quotation would flag every honest
claim. A false positive the user glances at and dismisses is much cheaper than a
false negative that lets an invented FDA clearance through.

---

## 6. Personalization

Valid personalization is anything the record actually contains: the company name,
what the description says they do, the recipient's role, their industry, their
specialty, the stated use case.

Invalid personalization is *"I saw your recent post"* — unless a note in the
database literally says so. §11: never pretend research was performed.

This is enforceable rather than advisory because the model has no other source. It
cannot fetch, search or browse. `EmailContextBuilder` renders database rows and
nothing else.

When three or more useful fields are blank, the lead page shows *"Additional
information may improve personalization"* with the specific gaps named. It is a
nudge, never a block.

---

## 7. Approval workflow

`EmailDraftStatus` owns the rules, so every caller asks the same question the
same way.

| Status | Editable | Approvable | Sendable |
| --- | :---: | :---: | :---: |
| Draft | ✓ | ✓ | — |
| User Edited | ✓ | ✓ | — |
| Approved | — | — | **✓** |
| Queued | — | — | — |
| Sent | — | — | — |
| Failed | — | — | — |
| Rejected | — | — | — |
| Archived | — | — | — |

**Approved is the only sendable status**, and `EmailDraftEditor::approve()` is
the only thing anywhere that sets `approved_at`.

### The approval dialog

Shows recipient, subject, product and the full preview — because approving is the
moment you take responsibility for what the model wrote. It says plainly:
*"Approving marks this email ready to send. It does not send it."*

### Blocking vs. warning

Approval is refused only for a `fail`. §26 is explicit that minor issues must not
block, and a checker that stops you sending a perfectly good 190-word email is one
you learn to click past.

| Check | Severity |
| --- | --- |
| Recipient exists and is a valid address | **fail** |
| Subject present | **fail** |
| Body present | **fail** |
| No `[placeholder]` | **fail** |
| Product still exists | warn |
| Greeting present | warn |
| Call to action present | warn |
| No AI meta-language | warn |
| Within the length band (+25% overshoot allowed) | warn |
| Signature configured | warn |

Recomputed on every save, so the panel never shows a verdict about text that has
since changed.

### Editing revokes approval

Approving text and then changing it clears `approved_at` and drops the status back
to User Edited. Sending something nobody signed off on is exactly the failure this
whole phase exists to prevent.

---

## 8. Versioning

Nothing is ever overwritten.

```
email_generations          one row per AI run
  └── email_drafts         one row per variant (three per run)
        └── email_draft_versions   one row per saved state
```

- `email_drafts.ai_subject` / `ai_body` are written once at generation and never
  updated. "What did the model actually write" always has an answer, however
  heavily the draft was rewritten afterwards. The editor offers **Compare with
  the AI original**.
- `email_draft_versions` is insert-only. Version 1 is always the AI's output;
  every save appends. The application never updates or deletes a row there, which
  makes "do not overwrite the original" a property of the schema rather than a
  promise in a controller.
- The version number is derived from `MAX(version)` in that table, not from a
  counter on the draft — the table owns the numbering and carries the unique
  constraint.

### Regeneration

Regenerating inserts a **new** `email_generations` row with
`regenerated_from_id` pointing at the old one, plus three fresh drafts. The old
run is untouched.

The lead page then offers **Keep previous** / **Use new version**. Either way the
losing set is *archived*, not deleted — a regeneration you rejected stays
readable, which is the point of keeping versions at all.

---

## 9. Signature

**The AI never writes the signature.** `EmailValidator` strips any sign-off block
the model produced, and `EmailRenderer` appends the configured one.

This is not fussiness. A model asked to sign off invents a job title and a phone
number, and a wrong phone number in outreach sent in your name is a real problem.

Configured in **Settings → Email**:

```
sender_name           sender_email
sender_job_title      sender_phone
sender_company        sender_website
                      sender_linkedin
```

The signature is composed from those fields, one per line, skipping blanks — so
an incomplete profile never produces a line with a label and nothing after it. A
hand-written `signature` overrides the composition entirely; **Reset to composed**
clears it.

An unconfigured signature produces a plain unsigned email, not trailing
whitespace. The quality check warns about it without blocking.

---

## 10. Simulated sending

`LocalTestEmailService` is the only implementation of `EmailServiceInterface` in
Phase 4. It:

1. Refuses anything not `Approved` (`EmailNotApprovedException`).
2. Renders the email including the signature.
3. Writes it — recipient, subject, full body, timestamp, draft id — to the local
   Laravel log.
4. Returns `EmailSendResult(simulated: true, mode: 'simulated')`.

Nothing opens a socket. The draft is marked `Sent` with
`delivery_mode = 'simulated'`, so a simulated send can never be mistaken for a
real one when reading the record back. An activity is recorded on the lead
timeline saying so explicitly.

**Test send** is gated by `EMAIL_ALLOW_TEST_SEND`, which defaults to on outside
production (§21).

---

## 11. Email preview

`EmailRenderer::render()` returns exactly what the recipient would see: from, to,
subject, body, signature.

It carries **nothing internal** — no confidence score, no reasoning, no product
matching, no prompt, no model name, no notes, no AI logs (§33). A test asserts
those keys are absent from the payload handed to the browser.

---

## 12. Security

| Risk | Handling |
| --- | --- |
| AI-generated HTML/JS | stripped at generation (`EmailValidator`) **and** on every user edit (`UpdateEmailDraftRequest::prepareForValidation`) |
| Arbitrary code execution | the model returns JSON that is decoded and validated; nothing is ever evaluated |
| AI database queries | the model never sees or emits SQL; every field is written through Eloquent from a validated array |
| AI file access | none — no file API is exposed to the model |
| AI shell commands | none |
| Automatic external communication | no transport exists that can send |
| Mass-assignment via settings | `EmailSettings::WRITABLE_KEYS` whitelist; unknown keys are dropped |

Plain text is the only format. There is no HTML email path to sanitise because
there is no HTML email.

---

## 13. Database

### `email_generations`
One AI run. Immutable by convention — regenerating inserts a new row.

`lead_id` (cascade), `contact_id`, `product_id`, `lead_product_match_id`
(nullOnDelete), `provider`, `model`, `tone`, `length`, `extra_instructions`,
`personalization_points`, `claims_used`, `missing_information`, `warnings`,
`execution_time_ms`, `ai_request_id`, `regenerated_from_id`.

### `email_drafts`
One sendable email.

`email_generation_id` (cascade), `lead_id` (cascade), `contact_id`, `product_id`,
`lead_product_match_id`, `variant`, `status`, `subject`, `body`, `ai_subject`,
`ai_body`, `recipient_email`, `recipient_name`, `personalization_points`,
`quality`, `word_count`, `ai_model`, `ai_request_id`, `created_by`, `version`,
`approved_at`, `sent_at`, `delivery_mode`, `delivery_error`.

`recipient_email` is snapshotted at generation so a sent record stays truthful if
the contact is later edited or deleted.

### `email_draft_versions`
Insert-only history. `email_draft_id` (cascade), `version`, `subject`, `body`,
`source` (`ai` | `user`), `word_count`. Unique on `(email_draft_id, version)`.

### `email_settings`
Local key/value store for the signature and preferences. Deliberately separate
from `ai_settings`, whose whitelist exists to stop the browser repointing AI
traffic — mixing signature fields in would blur what that whitelist protects.

### `ai_requests`
Gains `email_generation_id`. Every generation is logged with
`request_type = email_generation`, the full prompt, the raw response, execution
time and status. Local only, as in Phase 2.

---

## 14. Using it

### Generate

1. Open a lead. It needs a **contact with an email address**, a **company**, and
   at least one **accepted** product recommendation.
2. If any of those is missing, the Email Outreach panel says which — the button
   explains itself rather than failing on click.
3. Click **Generate personalized email**, pick the accepted recommendation,
   optionally add instructions, click **Generate**.
4. Three drafts appear. Expect one to two minutes on CPU.

Only *accepted* recommendations are offered. §5: the email follows the sales
direction you approved, not an unreviewed suggestion.

### Edit

Open a draft. Change the subject or body and **Save draft** — status becomes
User Edited, a version is appended, and the AI original stays intact.

### Approve

**Approve** → review the recipient, subject, product and full preview → confirm.
Blocking quality failures are refused with an explanation.

### Send

**Test send (simulated)** on an approved draft. Writes to the local log, marks the
draft Sent (simulated), records an activity. Nothing leaves the machine.

### Demo data

```bash
php artisan db:seed --class=SampleDataSeeder      # companies, contacts, leads
php artisan db:seed --class=EmailScenarioSeeder   # accept one recommendation each
php artisan email:scenarios                       # generate real emails, print them
```

`EmailScenarioSeeder` deliberately creates **no drafts**. Writing plausible emails
by hand and storing them as though a model produced them would put text in the
database that reads like AI output and is not — exactly what this application
exists to avoid.

---

## 15. Future email providers

The abstraction is in place; the implementations are not.

```
EmailServiceInterface
        │
        ├── LocalTestEmailService     ← the only one that exists
        ├── SmtpEmailService          ← later phase
        ├── GmailEmailService         ← later phase
        └── MicrosoftGraphEmailService ← later phase
```

`EmailServiceProvider` resolves the interface in one place. `EMAIL_DRIVER` values
`smtp`, `gmail` and `microsoft_graph` are recognised and **throw** — a typo can
never silently produce a transport nobody chose, which for a component whose job
is contacting real people is the failure mode worth being loud about.

Adding one means:

- OAuth for Gmail / Microsoft Graph, with tokens stored locally
- A real `send()` that returns `simulated: false` and its own `delivery_mode`
- Deciding what `Queued` and `Failed` mean in practice, and a retry story
- Re-reading §34: whatever is added must still refuse an unapproved draft

`EmailPrivacyTest::test_no_other_implementation_of_the_email_interface_exists()`
fails the moment a second implementation appears. That is intentional — it is the
reminder that real emails can now leave the machine.

---

## 16. Not in this phase

Explicitly out of scope (§39): Gmail OAuth, Outlook OAuth, real sending,
automatic follow-ups, email scheduling, lead discovery, website scraping for
outreach, autonomous agents.
