# Shvar AI Copilot

A private, local-only sales CRM with a local AI layer, for medical technology sales and business
development.

**Phase 1 — CRM/data foundation. Complete.**
**Phase 2 — local AI through Ollama. Complete.**
**Phase 3 — AI product recommendation. Complete.**

---

## 1. Project overview

Shvar AI Copilot manages the sales pipeline for medical technology products and services: companies,
the people at them, the leads you are working, and which of your products fit each one.

It is being built into a local AI sales copilot. Phase 1 built the data foundation. Phase 2 built the
local AI layer those features will run on — the service abstraction, the Ollama transport, structured
output, prompt templates and a local request log — plus a Settings area to configure and test it.

Phase 3 put that layer to work: opening a lead and pressing **Analyze Lead** reads the stored company
information, matches it against your product portfolio, and returns recommendations with reasoning,
evidence and a sales angle — for you to review. See
**[docs/PRODUCT_INTELLIGENCE.md](docs/PRODUCT_INTELLIGENCE.md)**.

Phase 4 added email outreach: from an accepted recommendation, the local model writes three
personalised drafts for you to edit and approve. **Nothing is ever sent without your approval.**
Sending is simulated by default; real delivery over your own SMTP server is opt-in and guarded by
a recipient allowlist. See **[docs/EMAIL_GENERATION.md](docs/EMAIL_GENERATION.md)**.

Follow-up drafting is a later phase and not yet built.

Phase 2 added a local AI layer: inference runs on your machine through
[Ollama](https://ollama.com). See **[docs/LOCAL_AI.md](docs/LOCAL_AI.md)** for the data-flow and
privacy detail, and section 9 below for setup.

### What is deliberately NOT present

| Not present | Why |
| --- | --- |
| OpenAI, Anthropic, Gemini, Azure, any cloud AI | Never — see Privacy |
| Gmail API / Microsoft Graph sending | Later phase — both need OAuth |
| Any third-party mail service (SendGrid, Mailgun…) | Never — SMTP to your own server only |
| Follow-up generation | Later phase |
| Email scheduling / automatic follow-ups | Later phase |
| RAG / embeddings / vector database | Later phase |
| Web scraping / lead enrichment for outreach | Later phase |
| Autonomous agents | Later phase |
| User accounts / login | Single-user local app |

The AI request types produced today are `general` (the Playground), `product_recommendation`
(Analyze Lead) and `email_generation` (Generate personalized email). Nothing generates the
follow-up type.

Three tests enforce the boundary: one fails the build if a cloud AI hostname appears anywhere in
`app/`, `resources/js/`, `routes/` or `config/`; one records every HTTP request the AI pages make and
asserts each went to a local address; one posts endpoint overrides at the settings form and asserts
the endpoint cannot be changed from the browser.

### Privacy

Everything runs on your machine.

- All data lives in one local SQLite file: `database/database.sqlite`
- **AI inference runs locally** through Ollama on `http://localhost:11434`
- No cloud database, no cloud AI, no API keys, no analytics, no telemetry
- No third-party fonts, scripts or CDNs — the default Laravel scaffold's Bunny Fonts
  dependency was removed and replaced with a system font stack
- AI requests go only to Ollama on loopback
- **One exception, added deliberately:** *Research from website* fetches the company URL you type,
  so the model reads real text instead of inventing facts. It sends only that HTTP request — no CRM
  data, no prompts. Disable with `RESEARCH_FETCH_ENABLED=false`. See
  [docs/COMPANY_RESEARCH.md](docs/COMPANY_RESEARCH.md)
- Served over `localhost` only

The accurate claim, rather than an absolute one: *AI inference is configured to run locally through
Ollama, and the application does not intentionally send AI requests to external cloud services. The
only outbound request it makes is fetching a company website you explicitly ask it to read.*
[docs/LOCAL_AI.md §4](docs/LOCAL_AI.md) sets out exactly what that covers and what it does not.

---

## 2. Requirements

| Requirement | Minimum | This machine |
| --- | --- | --- |
| PHP | 8.2+ | **8.4.23** at `C:\php\php.exe` |
| PHP extensions | `pdo_sqlite`, `mbstring`, `openssl`, `fileinfo`, `curl`, `zip`, `tokenizer`, `xml`, `ctype`, `bcmath` | all present |
| Composer | 2.x | 2.10.1 |
| Node.js | 20.19+ / 22.12+ | 24.15.0 |
| npm | 10+ | 11.12.1 |
| **Ollama** | any recent version | **0.32.14** ✅ installed and running |
| Local model | any | **qwen3:4b** ✅ pulled (2.5 GB) |
| `AI_TIMEOUT` | — | **600s** — an analysis takes ~4–5 min on CPU |

The CRM works fully without Ollama. Only the AI screens need it, and they degrade to a clear
"Not Connected" state rather than breaking.

> **This machine runs inference on the CPU.** The Quadro P600's driver (442.83) is too old for the
> CUDA runtimes Ollama ships, and its Vulkan driver lacks 16-bit storage — which crashed
> `llama-server` on every request. `OLLAMA_VULKAN=0` is set to skip the GPU. Expect roughly
> 7 tokens/sec, and see [docs/LOCAL_AI.md](docs/LOCAL_AI.md) for how to get the GPU working.

> **Important — PHP version on Windows.** `php` on this machine's `PATH` resolves to XAMPP's
> **PHP 8.1.25**, which is too old for Laravel 13. Use `C:\php\php.exe` (8.4.23) for every command,
> or put `C:\php` ahead of XAMPP on your `PATH`:
>
> ```powershell
> $env:PATH = "C:\php;" + $env:PATH   # current shell only
> ```
>
> The `sqlite3.exe` CLI is **not** required — Laravel talks to SQLite through PHP's `pdo_sqlite`.

---

## 3. Installation

```powershell
cd "d:\Jay Prajapati\3D surgical\Shvar\sales-copilot"
$env:PATH = "C:\php;" + $env:PATH

# PHP dependencies
php C:\ProgramData\ComposerSetup\bin\composer.phar install

# JavaScript dependencies
npm install
```

## 4. Environment setup

`.env` is created by the installer. If you are starting from a fresh clone:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

The settings that matter:

```dotenv
APP_NAME="Shvar AI Copilot"     # rename the app here — it flows to the UI, page titles and sidebar
APP_URL=http://localhost:8000
DB_CONNECTION=sqlite         # database/database.sqlite
VITE_APP_NAME="${APP_NAME}"  # used in browser tab titles
```

**Renaming the app:** change `APP_NAME` in `.env`, then `php artisan config:clear` and
`npm run build`. Nothing hard-codes the name.

## 5. Database setup

SQLite needs no server. Create the file and the schema:

```powershell
php artisan migrate
```

If `database/database.sqlite` does not exist, create it first:

```powershell
New-Item -ItemType File database\database.sqlite
```

To rebuild from scratch at any time (**destroys all data**):

```powershell
php artisan migrate:fresh --seed
```

## 6. Running Laravel

```powershell
$env:PATH = "C:\php;" + $env:PATH
php artisan serve
```

→ <http://127.0.0.1:8000>

## 7. Running Vue

In a **second terminal**, for hot module reload while developing:

```powershell
npm run dev
```

For everyday use you do not need the dev server — build the assets once and `php artisan serve`
alone is enough:

```powershell
npm run build
```

| Command | Purpose |
| --- | --- |
| `npm run dev` | Vite dev server with HMR |
| `npm run build` | Production bundle into `public/build` |
| `npm run type-check` | `vue-tsc` over every `.vue` / `.ts` file |

## 8. How to seed the database

```powershell
# Your seven products and services (the default seed)
php artisan db:seed

# Optional: demo companies, contacts and leads to click around in
php artisan db:seed --class=SampleDataSeeder

# Optional: accept one recommendation per demo lead, so emails can be generated
php artisan db:seed --class=EmailScenarioSeeder
```

`DatabaseSeeder` loads **only the product portfolio** — companies, contacts and leads are your
real data and should come from manual entry or CSV import, not fixtures. `ProductSeeder` uses
`updateOrCreate` keyed on product name, so re-running it refreshes the catalogue without creating
duplicates.

`SampleDataSeeder` is opt-in and invents seven companies using `.example` domains (reserved by
RFC 2606, so they can never resolve). Remove it all with `php artisan migrate:fresh --seed`.

`EmailScenarioSeeder` accepts one sensible product recommendation on each demo lead — the step you
would otherwise do by hand — so **Generate personalized email** is immediately clickable. It creates
no drafts on purpose: hand-written text stored as though a model produced it is exactly what this
application exists to avoid. For real drafts, run `php artisan email:scenarios` with Ollama up.

### CSV import

`/import` creates a company, a contact and a lead per row, in three steps: choose a file →
review the parsed preview → commit. Nothing is written until you confirm.

- Recognised headers (order irrelevant, case and separators ignored — `Company Name`,
  `company_name` and `COMPANYNAME` all match): Company Name, Website, Country, State, City,
  Contact First Name, Contact Last Name, Job Title, Department, Email, Phone, LinkedIn, Industry,
  Company Type, Lead Source, Lead Status, Priority, Notes
- Unrecognised columns are listed and skipped, not treated as an error
- Each row needs at least a company name **or** an email
- Blank Lead Status defaults to `New`; blank Priority to `Medium`
- **Duplicate detection** compares normalised company names (`Acme Medical, Inc.` matches
  `acme medical inc`) and contact emails, against both the database and earlier rows in the same
  file. Duplicates are skipped unless you opt in, in which case the existing company/contact is
  reused and only a new lead is added
- Invalid rows are always skipped, with a per-row reason
- Limits: 10 MB, 5000 rows
- A blank template is downloadable from the page

### Bulk actions

Every list page (Leads, Companies, Contacts, Products) has a checkbox per row. Select some and a
bar appears at the bottom of the screen offering **Edit fields** and **Delete**.

- Selection survives paging and filtering. Select five leads on page 1, page forward, select three
  more, and act on all eight — the bar says how many are off-screen. The header checkbox is
  "select all on this page", not "select everything".
- **Edit fields** only offers fields that make sense set identically across many records. Names,
  emails, descriptions and notes are per-record and are deliberately absent.

  | Page | Editable in bulk |
  | --- | --- |
  | Leads | Lead status, Priority, Lead source, Assigned to |
  | Companies | Industry, Company type, Country, State, City |
  | Contacts | Company, Department, Country, City |
  | Products | Category, Active |

- Each field has three explicit states: **Leave unchanged** (the default), **Set to…** and
  **Clear**. Blank never means "empty this column" — an untouched field is left exactly as it is on
  every record. Only fields that are optional on the single-record form offer Clear at all, so
  Lead status and Priority cannot be emptied.
- A bulk status change writes the same activity-timeline entry per lead that changing it one at a
  time would, so the history stays honest.
- Deletes state their consequences before you confirm: deleting companies keeps their contacts and
  leads (detached), deleting leads takes their product opportunities and AI analysis history with
  them, and deleting products removes every lead opportunity referencing them.
- Limit: 500 records per action. The server re-validates every field against the model's own
  definition, so nothing the browser sends can write a column that is not on the list above.

---

## 9. Local AI setup

The CRM needs none of this. The AI screens need all of it.

### 1. Install Ollama

Download from **[ollama.com](https://ollama.com)** and install. On Windows it then runs in the
background (system tray) whenever launched.

```powershell
ollama --version
```

### 2. Start Ollama

Launch it from the Start menu if it is not already running. Confirm it is listening:

```powershell
curl http://localhost:11434/api/version
```

### 3. Install a compatible local model

Nothing is downloaded automatically — model files are gigabytes, so that stays your decision.

```powershell
ollama pull qwen3:8b      # a reasonable ~8B starting point
# or
ollama pull llama3
ollama pull phi3:mini     # smaller and faster, less capable

ollama list               # what you now have
```

Pick a model that follows instructions and produces clean JSON — see
[docs/LOCAL_AI.md §5](docs/LOCAL_AI.md) for why those two properties matter more than benchmarks
here.

### 4. Configure `OLLAMA_URL`

```dotenv
OLLAMA_URL=http://localhost:11434
```

**This is server configuration only.** It is not editable from the browser, and only local hosts
(`localhost`, `127.0.0.1`, `::1`) are accepted — a remote address is refused before any request is
sent. That is deliberate: it is what stops a typo forwarding your CRM data to a third party.

### 5. Configure `OLLAMA_MODEL`

```dotenv
OLLAMA_MODEL=qwen3:8b
```

Set it to a model you actually pulled. The app checks whether it is installed and says so plainly if
not — it does not assume. You can also change the active model from the UI later, which overrides
this default.

After editing `.env`:

```powershell
php artisan config:clear
```

### 6. Start Laravel

```powershell
$env:PATH = "C:\php;" + $env:PATH
php artisan serve
```

### 7. Open Shvar AI Copilot

<http://127.0.0.1:8000>

### 8. Go to Settings → AI

Sidebar → **Settings**. You should see:

```
AI Provider       Ollama
Ollama URL        http://localhost:11434   (read-only)
Configured Model  qwen3:8b

Connection        ● Connected
Model             ● Installed
```

If either light is red, the page states the reason and the fix.

### 9. Test the connection

Press **Test AI Connection**. It performs a real, minimal round trip — so a green result means the
model actually replied, not merely that the port is open:

```
Connection successful. Model qwen3:8b responded in 1.82s.
```

The first request is slower because the model has to load into RAM.

### Then try the Playground

**Settings → AI Playground** — enter a prompt, press **Run Local AI**, and you get the response,
execution time, model used, and token counts. Tick **Structured output (JSON)** to test constrained
JSON generation.

Every request lands in **Settings → AI Logs** with the full prompt and response, stored locally.

### AI environment reference

| Variable | Default | Editable in UI |
| --- | --- | --- |
| `AI_PROVIDER` | `ollama` | No |
| `OLLAMA_URL` | `http://localhost:11434` | **No — by design** |
| `OLLAMA_MODEL` | `qwen3:8b` | Yes |
| `AI_TEMPERATURE` | `0.7` | Yes |
| `AI_TIMEOUT` | `300` (seconds) | Yes |
| `AI_MAX_TOKENS` | unset (model default) | Yes |
| `AI_PROBE_TIMEOUT` | `5` (seconds) | No |
| `AI_MAX_PROMPT_CHARS` | `20000` | No |
| `AI_LOGGING_ENABLED` | `true` | No |

Full detail, data-flow diagram and troubleshooting: **[docs/LOCAL_AI.md](docs/LOCAL_AI.md)**.

---

## 10. Project architecture

```
Browser
   │  Inertia (no REST layer, no CORS, no API tokens)
Laravel 13 ──▶ Controllers ──▶ Form Requests (validation)
   │                       ├──▶ Services (DashboardMetrics, CsvImporter)
   │                       ├──▶ AIServiceInterface ──▶ OllamaAIService ──▶ localhost:11434 ──▶ LLM
   │                       └──▶ Eloquent Models ──▶ SQLite
   └──▶ API Resources ──▶ typed props ──▶ Vue 3 + TypeScript pages
```

`OllamaAIService` is the only class that knows Ollama exists; everything else depends on
`AIServiceInterface`. That is what makes the local runtime swappable, and what makes the privacy
property auditable — there is exactly one place an outbound call could originate.

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full picture and
[`docs/LOCAL_AI.md`](docs/LOCAL_AI.md) for the AI data flow.

```
app/
  Contracts/Ai/            ProductMatcher — declared, NOT implemented (Phase 3)
  Enums/                   LeadStatus, Priority, RecommendationType, ActivityType, LeadSource,
                           AiRequestType, AiRequestStatus
  Http/Controllers/        thin; one per module
  Http/Controllers/Settings/  AiSettingsController, AiPlaygroundController, AiLogController
  Http/Requests/           all validation
  Http/Resources/          the exact shape sent to Vue
  Models/                  Eloquent + relationships; AiRequest, AiSetting
  Models/Concerns/         HasActivities (polymorphic timeline + cleanup on delete)
  Providers/               AiServiceProvider — the ONE place AIServiceInterface is bound
  Services/                DashboardMetrics, CsvImporter
  Services/AI/             AIServiceInterface, OllamaAIService, AiResult, AiStatus,
                           AiSettings, AiRequestLogger, StructuredResponseParser,
                           LocalEndpointGuard, PromptTemplate, PromptLibrary
  Services/AI/Exceptions/  one per failure mode, each with a user-safe message
config/ai.php              provider, endpoint, defaults, limits, allowed_hosts
database/
  migrations/              8 tables (6 domain + ai_requests + ai_settings)
  seeders/                 ProductSeeder (default), SampleDataSeeder (opt-in)
  factories/               for tests
resources/js/
  Layouts/AppLayout.vue    sidebar + header + toasts
  Pages/                   one directory per module
  Pages/Settings/          Ai.vue, AiPlayground.vue, AiLogs.vue
  Components/              DataTable, Modal, ConfirmDialog, ToastHub, EmptyState, FilterBar,
                           Pagination, Badge, StatCard, FormField, DetailList, ActivityTimeline
  Components/Ai/           LocalAiNotice, StatusDot, SettingsTabs
  Components/Forms/        Company/Contact/Lead/Product form modals
  types/                   models.ts, ai.ts, ui.ts
  routes.ts                typed URL builders — no Ziggy dependency
tests/Feature/             176 tests (87 Phase 1 + 89 Phase 2)
```

### Database structure

| Table | Purpose | Key relationships |
| --- | --- | --- |
| `companies` | organisations | → many contacts, many leads |
| `contacts` | people | → belongs to company (nullable), many leads |
| `leads` | opportunities | → belongs to company + contact (both nullable) |
| `products` | your portfolio | → many leads via `lead_product_matches` |
| `lead_product_matches` | which products fit a lead | lead ↔ product, unique per pair |
| `activities` | timeline entries | polymorphic to lead / company / contact |
| `ai_requests` | local AI request log | standalone; never transmitted |
| `ai_settings` | locally saved AI settings | key/value; endpoint deliberately excluded |

`lead_product_matches` already carries `recommendation_type` and `confidence_score`. Phase 1 and 2
only ever write `Manual` with a null score; the Phase 3 AI matcher fills those in **without a
migration**.

`ai_settings` is key/value so Phase 3 can add settings without a migration. It holds only `model`,
`temperature`, `timeout`, `max_tokens` and `system_prompt` — the endpoint is **not** a permitted key,
which is what prevents the browser from repointing AI traffic.

Deleting a company or contact uses `nullOnDelete` — its leads survive, detached. Deleting a lead
cascades to its product matches, and the `HasActivities` trait removes its timeline (a polymorphic
relation cannot carry a database foreign key, so this is done in PHP).

Statuses and priorities are stored as plain strings, not database enums: SQLite has none, and
strings port to PostgreSQL unchanged. The PHP enums are what enforce the values.

### Portability to PostgreSQL

Nothing is SQLite-specific — no raw SQLite SQL, no `PRAGMA`, all schema via migrations, all
queries via Eloquent. Switching later is a `.env` change plus `php artisan migrate`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=sales_copilot
```

The one thing to revisit is the `like` in the search scopes — case-insensitive in SQLite,
case-sensitive in PostgreSQL, where it should become `ilike`.

---

## 11. What is built

**Fully implemented**

- **Dashboard** — 8 live count cards, a scaled pipeline visual across 10 stages, priority
  breakdown, recently-updated leads. Every figure is a real query; an empty database shows zeros
  and a first-run empty state, never invented statistics
- **Companies** — full CRUD, search, filter by country/industry/type, detail page with contacts,
  leads, associated products, notes and activity timeline
- **Contacts** — full CRUD, search across name/email/title/company, filter by company/department/
  country, detail page with company, leads, notes and timeline
- **Leads** — full CRUD, search by company/contact/email, filters for status/priority/country/
  company/source, one-click status moves, manual product matching, activity timeline
- **Product Portfolio** — all seven products pre-seeded, card grid, category and active filters,
  detail page, fully editable
- **CSV import** — preview, validation, duplicate detection, per-row error reporting, template
  download

**Phase 2 — local AI layer**

- **Settings → AI** — live connection and model status, read-only endpoint, model picker fed by
  Ollama's own list, temperature / timeout / max-tokens, editable system prompt, log summary,
  environment panel, and a **Test AI Connection** button that does a real round trip
- **Settings → AI Playground** — prompt editor with per-run model/temperature/max-tokens, a JSON
  mode, response + parsed JSON, execution time, token counts, and example prompts
- **Settings → AI Logs** — every request with status, model and duration; click through for the full
  prompt and response; filter by status/type/model; search; delete one or clear all
- **`AIServiceInterface` → `OllamaAIService`** — `generate()`, `generateStructured()`,
  `isAvailable()`, `getModels()`, `hasModel()`, `status()`, `ping()`
- **Structured output** — Ollama's `format` constrains generation; `StructuredResponseParser`
  recovers from code fences and surrounding commentary using brace-balanced scanning, validates
  required keys, and fails loudly rather than returning a guess
- **Prompt architecture** — `PromptTemplate` (immutable, `{{ placeholder }}` substitution) and
  `PromptLibrary` (the base system prompt, locally overridable). No prompt is built in a controller
- **Local request log** — `ai_requests`, written for successes *and* failures
- **Security** — `LocalEndpointGuard` blocks any non-local endpoint before a byte is sent; the
  endpoint is not user-writable; prompt and response sizes are capped; every failure mode has a
  typed exception with a user-safe message

**Phase 3 — AI product recommendation**

- **AI Sales Intelligence on the lead page** — **Analyze Lead** reads the stored company, contact
  and lead record, matches it against the active portfolio, and returns a company reading, a primary
  recommendation, secondary ones, products to avoid, missing information and a next action
- **Evidence, not assertions** — each recommendation carries quotes from the stored record that
  justify it, so a claim can be checked against the data it came from
- **Confidence calibration** — a model's score is capped at what the record can actually support; the
  original is kept and the UI says when it was lowered
- **Planning modules** — a recommendation may name a capability inside a product (e.g. *Knee
  Planning*), validated against that product's own `key_features`; an invented module is dropped
- **Human review** — every AI recommendation is stored `Suggested`; Accept/Reject are the only ways
  that changes, and accepting records an activity on the timeline
- **Full history** — re-running adds an analysis rather than replacing one; past runs stay readable
- **Manual override** — hand-picking a product still works exactly as before, and is never blocked
  by AI output
- **`AiProductMatcher`** — implements the Phase 1 `ProductMatcher` contract, depending only on
  `AIServiceInterface`
- **`RecommendationValidator`** — drops invented product ids, recovers right-name/wrong-id, rejects
  invalid modules, coerces malformed confidence, and surfaces what it filtered

**Phase 4 — AI personalized sales email**

- **Email Outreach on the lead page** — **Generate personalized email** writes three variants
  (direct, consultative, executive-short) from an *accepted* Phase 3 recommendation. When it cannot
  run, the panel says which of contact / email address / company / accepted recommendation is missing,
  so the button explains itself instead of failing on click
- **Nothing is sent without approval** — generation produces drafts and `Approved` is the only
  sendable status. The check exists in three places (controller, editor, transport) because a gap in
  one must not be a gap in the system
- **Sending is simulated until you say otherwise** — `EMAIL_DRIVER=local` writes the message to the
  local log and contacts nobody. Real SMTP delivery is opt-in, and while `EMAIL_ALLOWED_RECIPIENTS`
  is set only those addresses can receive anything, so the whole flow can be exercised for real
  without a prospect getting a half-finished email. The rail lives in `.env`, not the settings page —
  a guardrail you can switch off by clicking is not much of a guardrail
- **The SMTP password is encrypted at rest and never sent to the browser** — the settings page is
  told `password_set: true` and nothing more
- **The signature is never AI-written** — the validator strips any sign-off block the model
  produced and the application appends yours. A model asked to sign off invents a job title and a
  phone number, and a wrong phone number in outreach sent in your name is a real problem
- **Claims are checkable** — the model must enumerate every factual statement it made about the
  product in `claims_used`; each is compared against the product record and anything largely absent
  from it is flagged for review
- **Placeholders block approval** — `Hi [First Name],` is dropped at generation and refused at
  approval. Minor issues (no greeting, slightly long, no signature configured) warn without blocking
- **Version history** — the model's original wording is written once and never updated; every save
  appends a version; regenerating adds a new run alongside the old one with **Keep previous** /
  **Use new version**. Nothing is overwritten or deleted
- **Preview shows only what the recipient would see** — no confidence score, reasoning, model name
  or internal notes. A test asserts those keys are absent from the payload sent to the browser
- **Settings → Email** — sender profile, composed or hand-written signature, tone
  (Professional / Consultative / Technical / Executive / Friendly) and length (Short / Standard /
  Detailed). No mail host, port or credential exists to configure, because nothing can send
- **Email Drafts page** — list, filter by status / variant / product / company, search, open the
  editor with quality panel, preview, version history and approval

See [docs/EMAIL_GENERATION.md](docs/EMAIL_GENERATION.md) for the full workflow, prompt architecture
and threat model.

**Placeholders** (navigation present, scope stated on the page, no AI behind them)

- Follow-ups · Knowledge Base

**Still declared but NOT implemented**

- `AiRequestType::FollowUpGeneration` — declared, marked unbuilt in the UI, never generated.
  A test asserts this
- No real email sending, mailbox integration, OAuth, scheduling, scraping or RAG exists anywhere in
  the codebase. `EmailPrivacyTest` asserts this against the source tree, not just against behaviour

### Test results

```
php artisan test        →  410 passed, 4492 assertions
npm run type-check      →  clean
npm run build           →  clean
```

| Suite | Covers |
| --- | --- |
| `ApplicationSmokeTest` | every page renders empty and populated; CRM pages send zero HTTP; every AI request stays on localhost; no cloud AI hostname in source; ProductMatcher still unbound |
| `Ai/OllamaServiceTest` | binding, availability, model discovery, bare-name tag matching, status snapshots, request shape, and every failure mode (refused, timeout, 404, 500, non-JSON, empty, oversized) |
| `Ai/StructuredOutputTest` | clean JSON, `format` forwarding, schema forwarding, required-key enforcement, fence stripping, commentary extraction, braces inside strings, escaped quotes, nesting, rejection of arrays/scalars |
| `Ai/AiLoggingTest` | success and failure logging, per-status mapping, execution time, structured flag, storage cap, logging disabled, survival of a log write failure |
| `Ai/AiSettingsTest` | config fallback, persistence across requests, validation, clamping, system-prompt override/reset, and the endpoint boundary (config-only, unwritable, guard accept/reject, zero bytes sent to a remote endpoint) |
| `Ai/AiPagesTest` | all three screens with Ollama up and down, connection test success/failure/missing-model, playground runs, structured runs, validation, friendly errors, log list/detail/filter/delete |
| `Ai/ProductRecommendationTest` | analysis shape, primary/secondary typing, everything starts awaiting review, lead untouched, prompt contents, inactive products and private sales notes excluded, logging against the lead, history accumulation, and every failure mode |
| `Ai/RecommendationValidationTest` | invented product ids, wrong-id/right-name recovery, duplicates, invalid modules, confidence coercion and clamping, ordering, primary fallback, and confidence calibration |
| `Ai/LeadAnalysisPagesTest` | analyse over HTTP, empty result as a valid outcome, friendly errors, accept/reject/archive, cross-lead guards, history listing and detail, manual override |
| `DashboardTest` | zeros on empty DB, real counts, pipeline scaling, ordering |
| `CompanyTest` | CRUD, search, filters, relation counts, `nullOnDelete`, name normalisation |
| `ContactTest` | CRUD, search, validation, lead survival on delete |
| `LeadTest` | CRUD, filters, status-change timeline entries, manual product matching, cross-lead guards |
| `ProductTest` | seeder correctness + idempotence, filters, list-field splitting, cascade |
| `CsvImportTest` | header matching, defaults, invalid rows, duplicates, BOM, blank lines, template |
| `BulkActionsTest` | bulk edit whitelist, blank-is-not-clear, explicit clear, required fields unclearable, per-record timeline entries, bulk delete cascades |
| `Email/EmailGenerationTest` | three variants, everything starts as Draft, recipient snapshotting, prompt contents, schema forwarding, dropped placeholders and self-written signatures, stripped HTML, unsupported-claim flagging, regeneration history, and every failure mode |
| `Email/EmailApprovalTest` | editing appends versions and never overwrites the AI original, approval sets the timestamp and logs an activity, editing revokes approval, unapproved drafts refuse to send at all three layers, simulated send writes only to the local log |
| `Email/EmailSignatureAndQualityTest` | composed vs hand-written signature, blank-field handling, the signature being appended rather than stored, every quality check and its severity, settings whitelist and validation |
| `Email/EmailPagesTest` | drafts list and filters, editor payload, version history, the preview carrying nothing internal, the lead page explaining why generation is blocked, no mail server exposed in settings |
| `Email/EmailPrivacyTest` | no cloud AI hostname in source, only one `EmailServiceInterface` implementation, unimplemented drivers throw, Laravel Mail unused, no outbound call anywhere in the email layer, no OAuth or scheduling route |

Ollama does **not** need to be running for the suite to pass — every Ollama response is faked.

Also verified manually over HTTP against a running server: all CRM pages plus every detail and
filtered view returned 200, and create/update/delete/attach/activity requests succeeded through the
real CSRF and Inertia middleware stack (422 on invalid input, 404 on cross-record access).

For Phase 2, because Ollama is not installed on this machine, the real HTTP path was additionally
driven against a **local stub that speaks Ollama's API** (`/api/version`, `/api/tags`,
`/api/generate`). That is a stub, not Ollama — but it exercises the genuine transport, parser,
logger and UI rather than mocks. Verified through the browser flow: status probe, connection test
("Model qwen3:8b responded in 0.24s"), plain and structured runs, fence recovery, and the
model-missing / unparseable-JSON / empty-completion / bad-model-override / empty-prompt failure
paths — each producing its correct user-safe message and its correct log status.

### Known limitations

1. **No authentication.** Anyone with access to your machine and the running server can use it.
   Intentional for a single-user local app; `php artisan serve` binds to `127.0.0.1` only.
2. **`assigned_to` is free text.** There are no user accounts to link to.
3. **Activity timeline is partly manual.** You can log Note / Call / Meeting; status changes are
   recorded automatically. Email and Follow-up entries are Phase 3.
4. **Product matching is entirely manual**, by design — the AI matcher is Phase 3.
5. **CSV import is one-shape.** One row = one company + one contact + one lead. Importing only
   companies, or several contacts per company in one file, needs repeated company names (handled
   correctly — the company is created once).
6. **CSV import re-parses the file on commit** rather than trusting the preview. Correct, but it
   means the file must still be selected in the browser.
7. **Search uses `like`**, so it is case-insensitive on SQLite but would need `ilike` on PostgreSQL.
8. **No pagination on Product Portfolio.** A portfolio of a few dozen is fine; thousands would need it.
9. **Deleting a product is destructive** to its lead matches. Marking it inactive is usually right,
   and the confirmation dialog says so.
10. **No soft deletes and no audit trail.** Deletions are permanent.
11. **`database/database.sqlite` is not backed up.** Copy the file to back it up.

Phase 2 specifically:

12. **Ollama is not installed on this machine.** The AI screens render a clear "Not Connected" state
    until you install it and pull a model. Everything else works regardless.
13. **No live verification against a real model.** The AI path was verified end to end against a
    local stub of Ollama's API, and exhaustively with mocked HTTP. Response *quality* — whether your
    chosen model follows the no-invented-facts instruction, and how reliably it produces JSON — can
    only be judged once you point it at a real model.
14. **No streaming.** Requests use `"stream": false`, so a long response arrives all at once with a
    spinner in the meantime. The service is shaped so a streaming method can be added without
    changing callers; it was not built, per the Phase 2 scope.
15. **Requests are synchronous.** A slow local model blocks that browser request until it finishes or
    the timeout fires. No queue worker is involved.
16. **The AI log grows unbounded.** Stored prompt/response text is capped per row, but the row count
    is not. Clear it from Settings → AI Logs when you want to.
17. **The base system prompt is not the one that matters most.** Each AI feature has its own
    specialised system prompt — product recommendation, company research, email generation — because
    each has a different failure mode. Editing the base prompt in Settings does not change those.
18. **Generation is slow on CPU.** An email takes one to two minutes on this machine, an analysis
    four to five. It runs inside the browser request, so the page waits. Getting the GPU working
    (see [docs/LOCAL_AI.md](docs/LOCAL_AI.md)) or moving generation to a queue worker are the two
    ways out.
19. **Unsupported-claim detection is a heuristic, not a proof.** It compares word overlap against the
    product record and is deliberately generous, so a fabricated claim phrased in the record's own
    vocabulary can slip past it. Read `claims_used` on the draft page before approving.
20. **Simulated sending writes the full email body to the local application log.** That is the point
    — it is how you check what would have gone — but the log is plain text on disk like every other
    log here.

---

## 12. Phase 5 planned

Not started. Nothing below exists in this codebase.

Phase 2 built the AI plumbing, Phase 3 pointed it at the product catalogue, Phase 4 turned an
accepted recommendation into a reviewable email. Phase 5 is where something finally leaves the
machine — which is a different kind of change from everything before it, and worth being slow about.

| # | Feature | Reads | Writes | Schema change |
| --- | --- | --- | --- | --- |
| 1 | **Real email sending** | approved drafts | send log, `delivery_mode` | small |
| 2 | **Follow-up generation** | lead + activity + sent email history | `activities` (`Follow-up` exists) | none |
| 3 | **Email scheduling** | approved drafts | a queue table | yes |
| 4 | **Local RAG / Knowledge Base** | uploaded documents | `documents` + a local vector store | yes |
| 5 | **Company analysis at scale** | company profiles | `company_analyses` (exists) | none |

### Recommended Phase 5 architecture

**Start with SMTP, not OAuth.** `EmailServiceInterface` already exists and
`EmailServiceProvider` already recognises `smtp`, `gmail` and `microsoft_graph` as driver names —
they throw today. SMTP is by far the smallest of the three: a host, a port, credentials, no consent
screen, no token refresh, no Google verification review. Getting one real transport working end to
end will surface every question the OAuth ones also raise, at a fraction of the cost.

Concretely:

- **`SmtpEmailService implements EmailServiceInterface`.** It must refuse a draft that is not
  `Approved`, exactly as `LocalTestEmailService` does — that check lives in the transport
  deliberately, so a new implementation cannot skip it by accident.
- **`EmailPrivacyTest::test_no_other_implementation_of_the_email_interface_exists()` will fail.**
  That is intentional. Updating it is the moment to re-read section 34 of the Phase 4 brief and
  decide, deliberately, that real emails may now leave this machine.
- **Credentials are the new privacy surface.** Everything stored so far is data you typed. An SMTP
  password is a secret, and `email_settings` is a plain-text local table — it belongs in `.env` with
  the AI endpoint, not in a user-writable store.
- **`Queued` and `Failed` need to start meaning something.** Both statuses exist and nothing sets
  them. A real transport makes `Failed` reachable, which makes retry a real question.
- **Sending should stay a foreground action with a confirmation**, at least at first. A background
  worker that sends approved drafts is a very short step from a system that sends without anyone
  watching.

**Then follow-ups**, which need sent-email history to be real before they mean anything.
`ActivityType::FollowUp` and `Email` are already declared, so no migration is needed — and Phase 4
already records an activity on every send.

**Defer scheduling.** It is where "human approval is mandatory" quietly erodes: a scheduled send is a
decision made once and executed later, possibly after the situation has changed. If it is built, the
approval should be re-confirmed at send time, not just at schedule time.

**Defer RAG until there is a reason.** It is the largest piece by far and the portfolio still fits in
a prompt. It becomes worthwhile when there are real documents — spec sheets, clinical papers — that
the model should quote from rather than paraphrase.

### Carried forward from Phases 2–4

- **Performance is the practical constraint, not capability.** An email takes one to two minutes on
  CPU, an analysis four to five. Both run inside a browser request today. Laravel's `database` queue
  driver is already configured and a job that takes minutes belongs in a worker.
- **Streaming is now genuinely worth adding.** Watching an email being written is a much better
  experience than watching a spinner for ninety seconds. `OllamaAIService` was shaped for it — all
  completions funnel through one method.
- **Small models under-fill large schemas.** Phase 3 needed an explicit "every field must be filled"
  instruction; Phase 4's schema requires only `variants` for the same reason. Prefer a compact schema
  over an exhaustive one.
- **The model will flatter if you let it.** Phase 3's failure mode was forcing a match, company
  research's was answering from memory, Phase 4's was marketing language and invented credentials.
  Whatever Phase 5 generates will have its own, and it is worth naming explicitly in the system
  prompt rather than hoping the base prompt covers it.
- **Human review is not optional.** Every generated artefact needs approval before it leaves the
  machine. That principle held through Phases 1–4 and matters most the moment sending is real.

Every step stays local. No cloud AI provider may ever be bound to `AIServiceInterface`.
