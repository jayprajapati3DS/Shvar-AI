# AI Personalized Sales Email Generation

Phase 4 of Shvar AI Copilot. The local model writes outreach emails from what the
CRM already knows, and a human decides whether any of them are worth sending.

**The one rule everything else is arranged around: nothing is ever sent without an
explicit human approval.**

Sending is simulated by default (`EMAIL_DRIVER=local`) and contacts nobody. Real
delivery over SMTP is opt-in and guarded by a recipient allowlist — see
[section 15](#15-real-sending-over-smtp).

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
                                       Send  ──▶  Sent
                            (simulated, or real over SMTP)
```

Two things cannot happen, by construction:

- **Generation cannot send.** `EmailGenerator` holds no reference to
  `EmailServiceInterface`. The two never meet.
- **An unapproved draft cannot be sent.** Checked in three places — the
  controller, `EmailDraftEditor::send()`, and inside the transport itself, which
  throws `EmailNotApprovedException` rather than trusting its caller. Both
  transports do this, so it holds however delivery is configured.

---

## 2. Privacy architecture

Identical to Phases 2 and 3. Nothing changed, and nothing was added.

```
Laravel  ──▶  AIServiceInterface  ──▶  OllamaAIService  ──▶  http://localhost:11434
                                                                    │
                                                              Local LLM
```

- No OpenAI, Anthropic, Gemini, Azure OpenAI, or any other cloud LLM. Every
  token is generated on this machine.
- No third-party email service. No Gmail API, no Microsoft Graph, no SendGrid,
  no Mailgun. The only way an email leaves is your own SMTP server, which you
  configure and switch on deliberately.
- No outbound HTTP anywhere in `app/Services/Email` — asserted by a test that
  greps the directory, with `SmtpEmailService` exempted by name so the exemption
  is visible rather than a loosened rule.
- Company, contact and product data are never transmitted anywhere. The only
  thing that can leave is an email you wrote, edited and approved, to the
  address on the draft.

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

## 4b. Pitching more than one product

One email can be written from up to **three** accepted recommendations.

- The first is the **primary**. The email is built around it, and it is what the
  drafts list shows and the filters match — "what is this email about" always has
  one answer.
- The rest are woven in only where the company data gives a specific reason,
  **at most one sentence each**. The model is told to drop one entirely rather
  than pad the email with it.

The cap is `EmailGenerator::MAX_PRODUCTS`. Past three the email stops being about
anything and becomes a list of what you sell, which is the failure the whole
style guide is arranged against — and the local model's ability to keep several
products straight degrades much faster than its ability to write one good
paragraph.

Every selected product must be **accepted** on that lead, not just the primary.
Claims are checked against all of them together, so a true sentence about the
second product is not flagged for being absent from the first one's record.

The set is stored on `email_generation_recommendations` with `is_primary` and
`position`, so an email that pitched three products can still say which three a
year from now.

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

## 8b. Learning from what you approve

**This is not training.** The model's weights are frozen, nothing here touches
them, and nothing leaves the machine. There is no fine-tuning and no gradient.

What it is: the signal you are already producing, fed back into the prompt. Every
time you approve one variant over another, shorten a draft or rewrite an opening,
you state a preference. That used to be thrown away and the next generation
started from the same blank slate.

`EmailStyleProfile` reads it back from drafts you **approved or sent**:

| Learned | From | Needs |
| --- | --- | --- |
| Which variant you prefer | approval counts | a clear lead — 1.5× the runner-up |
| Your real email length | median word count | 6 approved emails |
| Sentences you keep deleting | `ai_body` vs `body` on edited drafts | the same cut twice |
| Worked examples | your approved emails, edited ones first | 3 approved emails |

The rejected-phrases list is the most useful part. *"Stop writing this"* is a far
stronger instruction than any amount of style guidance, because it is specific
and it came from you.

### What it will not do

- **Learn from rejected or unreviewed drafts.** A draft you rejected is not a
  preference, and one still sitting in `Draft` is just the model's own output —
  learning from that would be the model teaching itself its own habits.
- **Assert a preference from a dead heat.** Telling the model to favour a style
  you pick half the time invents a signal rather than reading one.
- **State a word count from three samples.** A median of three is one short email
  away from claiming you write 40-word emails, and a stated number reads as an
  instruction. It needs six.
- **Outrank the truth rules.** The block is placed after the company and product
  data and says so explicitly: these are preferences about HOW to write, never
  about what is true. A worked example containing a claim is still a claim that
  has to come from the product record.

### Seeing and switching it

Settings → Email shows exactly what was concluded, including a **Show exactly
what gets added to the prompt** button that prints the block verbatim. A prompt
that silently rewrites itself is how a system quietly gets worse in ways nobody
can point at — if it has learned something silly, you can read it and turn the
whole thing off.

Only the most recent 40 approved emails are considered, so it tracks how you
write now rather than how you wrote a year ago.

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

## 15. Real sending over SMTP

Simulated sending is still the default. `EMAIL_DRIVER=local` contacts nobody, and
nothing switches it for you.

### Setup for Microsoft 365

**Settings → Email → Mail server (SMTP)**

| Field | Value |
| --- | --- |
| Host | `smtp.office365.com` |
| Port | `587` |
| Encryption | STARTTLS |
| Username | your full email address |
| Password | see below |

Then **Test connection** — it authenticates and stops, sending nothing.

**The Microsoft 365 caveat.** Many tenants have SMTP AUTH disabled, and Microsoft
has been switching it off by default. If the test fails with a 535 or
"SmtpClientAuthentication is disabled", one of these applies:

- **SMTP AUTH is off for the mailbox.** An admin enables it per-mailbox in the
  Microsoft 365 admin centre, or tenant-wide via
  `Set-TransportConfig -SmtpClientAuthenticationDisabled $false`.
- **MFA is on** (it usually is). A normal password will not work — you need an
  **app password**, which requires security defaults to be off and per-user MFA
  configured. Your IT will know which.
- **Conditional access** may block the sign-in regardless. The test message will
  say so.

If SMTP AUTH cannot be enabled on your tenant, Microsoft Graph with OAuth is the
supported route — that is a later phase and is not built.

### The recipient allowlist

**This is the guardrail, and it is on until you turn it off.**

```dotenv
EMAIL_ALLOWED_RECIPIENTS="dwij.mistry@3dsurgical.com"
```

While that is non-empty, `SmtpEmailService` delivers only to those addresses and
refuses everything else with an explanation. A leading `@` allows a whole domain,
so `@3dsurgical.com` lets you test with colleagues.

Clearing it removes the restriction.

It lives in `.env` rather than the settings page on purpose. The drafts are
addressed to real people at real medical technology companies, and a rail you can
switch off by clicking is not much of a rail — the same reasoning that keeps
`OLLAMA_URL` out of the AI settings form. The page shows the current value
read-only so you can see what is in force.

A refusal is **not** a delivery failure: the draft stays `Approved`, nothing is
marked `Failed`, and you can retry after editing the list.

### Going live

1. Configure SMTP in Settings and **Test connection**.
2. Put **your own address** in `EMAIL_ALLOWED_RECIPIENTS`.
3. Set `EMAIL_DRIVER=smtp` in `.env`.
4. Approve a draft and send it. Check what actually arrives.
5. Only then widen or clear the allowlist.

The settings page turns amber and the send button says **Send** rather than
**Test send (simulated)** once sending is real, because "simulated" and "this
reaches a person" must never look the same at a glance.

### How the credentials are stored

- The password is **encrypted at rest** with `APP_KEY`, via `Crypt::encryptString`.
  It is the only value in this application that is not plain text, because it is
  the only one that is a secret rather than data you typed.
- It is **never sent to the browser**. `SmtpSettings::toArray()` reports
  `password_set: true` and nothing more, so it cannot leak through an Inertia
  payload, a page cache or a screenshot. The form posts a sentinel when you did
  not retype it.
- If `APP_KEY` changes, the stored password stops decrypting and SMTP reads as
  unconfigured — "set it up again", not a 500.

### The three gates

`SmtpEmailService::send()` refuses, in order:

1. **Not approved** → `EmailNotApprovedException`. Checked here as well as in the
   controller and the editor, because a transport that trusts its caller is one
   refactor away from sending something nobody signed off on.
2. **Not on the allowlist** → `RecipientNotAllowedException`.
3. **Not configured** → refuses rather than attempting an anonymous relay.

It is driven through Symfony Mailer directly, never Laravel's `Mail` facade, so
`config/mail.php` — stock scaffolding pointing at whatever `MAIL_HOST` happens to
say — is never involved. Plain text only; nothing builds HTML.

`SmtpSendingTest` covers all three gates. No test opens a socket: the interesting
behaviour of a transport that can contact real people is everything it refuses to
do. A successful send is verified by a human, deliberately, with **Test
connection** and a first send to their own address.

---

## 16. Future email providers

The abstraction is in place; the implementations are not.

```
EmailServiceInterface
        │
        ├── LocalTestEmailService      ← simulated, the default
        ├── SmtpEmailService           ← real, opt-in via EMAIL_DRIVER
        ├── GmailEmailService          ← later phase (OAuth)
        └── MicrosoftGraphEmailService ← later phase (OAuth)
```

`EmailServiceProvider` resolves the interface in one place. `gmail` and
`microsoft_graph` are recognised and **throw** — a typo can never silently produce
a transport nobody chose, which for a component whose job is contacting real
people is the failure mode worth being loud about.

Adding one means:

- OAuth, with tokens stored locally and refreshed
- A `send()` that refuses an unapproved draft and honours the allowlist, exactly
  as `SmtpEmailService` does
- Deciding what `Queued` and `Failed` mean in practice, and a retry story

`EmailPrivacyTest::test_only_the_two_known_transports_exist()` fails the moment a
third implementation appears. That is intentional — it is the reminder that
adding a transport is the moment real email can leave the machine, and it should
not be possible to do it quietly.

---

## 17. Not in this phase

Explicitly out of scope (§39): Gmail OAuth, Outlook OAuth, real sending,
automatic follow-ups, email scheduling, lead discovery, website scraping for
outreach, autonomous agents.
