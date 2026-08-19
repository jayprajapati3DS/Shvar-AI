# Shvar AI Copilot — Architecture

How the application is put together, why, and how the local AI layer fits in.

---

## 1. Overview

A single Laravel application serving Vue 3 pages through Inertia. One process, one port, one
database file. The only external process it talks to is Ollama, on loopback.

```
┌──────────────────────────────────────────────────────────────┐
│  Browser — http://127.0.0.1:8000                             │
│  Vue 3 · TypeScript · Tailwind 4                             │
└───────────────────────────┬──────────────────────────────────┘
                            │  Inertia protocol (JSON page objects)
                            │  no REST layer · no CORS · no tokens
┌───────────────────────────▼──────────────────────────────────┐
│  Laravel 13 · PHP 8.4                                        │
│                                                              │
│   Routes ──▶ Controllers ──▶ Form Requests   (validation)     │
│                  │        └─▶ Services       (logic)          │
│                  │        └─▶ Eloquent       (persistence)    │
│                  └────────▶ API Resources    (response shape) │
│                                                              │
│   Services/AI/   ← AIServiceInterface → OllamaAIService       │
└──────────────┬────────────────────────────┬──────────────────┘
               │  PDO                       │  HTTP (loopback only)
┌──────────────▼──────────────┐  ┌──────────▼──────────────────┐
│  SQLite                     │  │  Ollama                     │
│  database/database.sqlite   │  │  http://localhost:11434     │
└─────────────────────────────┘  │        │                    │
                                 │        ▼                    │
                                 │  local LLM (model file)     │
                                 └─────────────────────────────┘
```

### Why Inertia rather than a REST API + separate SPA

For a single-user local CRM, a JSON API between two halves of the same app is cost without benefit.
Inertia gives real Vue SPA pages while keeping one server, one port, one session:

- No CORS configuration, no API tokens, no duplicated auth
- Server-side validation errors arrive directly in the Vue form object
- Controllers stay the single source of truth for what a page receives
- Roughly a third less code for the same features

API Resources are still used — they define the exact shape sent to the frontend, and they are what
a future REST API would reuse if another client ever needs one.

---

## 2. Frontend

**Stack:** Vue 3.5 · TypeScript 5.9 (strict) · Tailwind 4 · Vite 8

```
resources/js/
  app.ts                   Inertia bootstrap; assigns AppLayout to every page
  routes.ts                typed URL builders
  Layouts/AppLayout.vue    sidebar, header, toast host
  Pages/
    Dashboard.vue
    Companies/{Index,Show}.vue
    Contacts/{Index,Show}.vue
    Leads/{Index,Show}.vue
    Products/{Index,Show}.vue
    Import/Index.vue
    Settings/{Ai,AiPlayground,AiLogs}.vue
    Placeholders/{EmailDrafts,FollowUps,KnowledgeBase}.vue
  Components/
    DataTable.vue          generic table shell; rows via slot
    Modal.vue              teleported, Esc to close, body scroll lock
    ConfirmDialog.vue      destructive-action confirmation
    ToastHub.vue           renders the toast queue
    EmptyState.vue         every list and panel has one
    FilterBar.vue          debounced search + dropdowns, state in the URL
    Pagination.vue         Laravel paginator links
    Badge.vue              status/priority chips
    StatCard.vue           dashboard metric card
    FormField.vue          label + control + error
    DetailList.vue         definition list for detail pages
    ActivityTimeline.vue   the shared timeline
    PhaseTwoNotice.vue     placeholder-page body
    Ai/LocalAiNotice.vue   the "processed on this computer" indicator
    Ai/StatusDot.vue       connected / not connected light (three states)
    Ai/SettingsTabs.vue    tab bar across the Settings area
    Forms/*FormModal.vue   create/edit modals, one per entity
  composables/useToasts.ts
  types/models.ts          mirrors app/Http/Resources
  types/ai.ts              mirrors AiStatus / AiResult / AiSettings / AiRequestResource
  types/ui.ts              component prop types
```

### Conventions

- **`types/models.ts` mirrors the API Resources.** Pages are typed against it, so changing a
  resource and forgetting the frontend is a compile error, not a runtime surprise.
- **Component types live in `types/ui.ts`, not in the `.vue` files.** A `<script setup>` block is
  not a module that can export, so `import { type Column } from '...vue'` would break.
- **Create/edit forms are modals.** No separate `create`/`edit` routes — one form component serves
  both, switching to `PUT` when given a record.
- **Filters live in the query string**, not component state, so a filtered view is bookmarkable and
  survives refresh and back-navigation.
- **Tailwind classes are written literally.** Badge palettes are lookup maps of full class strings,
  never `bg-${color}-50`, because Tailwind's scanner only keeps classes it can see.
- **Icons are inlined SVG paths.** No icon package, no CDN.

### Tailwind 4 note

Tailwind 4's `@apply` only accepts real utilities, not other custom classes. The shared button base
is therefore declared once against a selector list (`.btn, .btn-primary, …`) rather than each
variant doing `@apply btn`.

---

## 3. Backend

### Controllers are thin

They resolve input, delegate, and return a response. No business logic.

| Controller | Responsibility |
| --- | --- |
| `DashboardController` | single invokable; delegates entirely to `DashboardMetrics` |
| `CompanyController` | company CRUD + filter option lists |
| `ContactController` | contact CRUD |
| `LeadController` | lead CRUD; records a timeline entry on status change |
| `ProductController` | product CRUD |
| `LeadProductMatchController` | attach/detach a product, manual only |
| `ActivityController` | polymorphic timeline entries, whitelisted subject types |
| `ImportController` | CSV: page, preview, commit, template |
| `Settings\AiSettingsController` | AI config, live status, connection test |
| `Settings\AiPlaygroundController` | the developer prompt screen; `general` requests only |
| `Settings\AiLogController` | local AI log: list, detail, delete, clear |
| `LeadAnalysisController` | run an analysis, view history, accept/reject/archive a recommendation |
| `PlaceholderController` | the three remaining stubs, with real counts |

No controller calls Ollama. They all depend on `AIServiceInterface`.

### Validation lives in Form Requests

`StoreCompanyRequest`, `StoreContactRequest`, `StoreLeadRequest`, `StoreProductRequest`,
`StoreLeadProductMatchRequest`, `StoreActivityRequest`, `ImportCsvRequest`,
`UpdateAiSettingsRequest`, `RunAiPromptRequest`.

Beyond field rules they carry the domain rules:

- A lead needs at least a company **or** a contact
- A contact from a different company cannot be attached to a lead
- A product cannot be attached to the same lead twice — caught in validation so the user sees a
  message rather than a unique-constraint 500
- `prepareForValidation` normalises bare domains (`acme.com` → `https://acme.com`)
- `StoreActivityRequest` accepts only the manual types; Email and Follow-up are system-written
- `UpdateAiSettingsRequest` has **no rule for the endpoint** — that is the point, not an omission
- `RunAiPromptRequest` bounds the prompt length so the user gets a field error rather than an
  exception, while the service-level check still protects non-HTTP callers

### Services hold the logic that is not a single query

- **`DashboardMetrics`** — every dashboard figure. Status counts come from one grouped query,
  memoised **per instance** (not `static`, which would go stale under a persistent worker such as
  Octane). Pipeline bars are scaled to the busiest stage so a 3-lead pipeline is still readable.
- **`CsvImporter`** — two-phase by design: `analyse()` parses, validates and detects duplicates
  while writing nothing; `import()` re-parses and commits inside a transaction. Existing companies
  and emails are loaded once up front, and an in-run company index means a 5000-row file does not
  re-read the companies table per row.
- **`Services/AI/*`** — the local AI layer. See section 5.
- **`Services/AI/Recommendation/*`** — the Phase 3 product-recommendation engine. See section 5b.

### Enums are the source of truth for domain values

`LeadStatus`, `Priority`, `RecommendationType`, `ActivityType`, `LeadSource`, `AiRequestType`,
`AiRequestStatus`.

`AiRequestType` also declares the Phase 3 cases and exposes `implementedCases()`, so the Settings
screen can honestly label company analysis, product recommendation, email generation and follow-up
generation as "Phase 3" rather than pretending they work.

They also own presentation concerns that would otherwise be duplicated in Vue — `color()` returns
the Tailwind token used by `Badge.vue`, and `options()` builds dropdown payloads. The pipeline
order, the opportunity stages, and which activity types a human may create are all defined here.

`LeadSource` is deliberately *not* enforced on the column: `lead_source` is free text so you can
type an ad-hoc source at a trade show. The enum only seeds the dropdown, and the lead-list filter
merges in any hand-typed values already saved.

### Models

```
Company ──┬─< Contact ──< Lead >── Product
          └─< Lead        (via lead_product_matches)

Lead ──< LeadAnalysis ──< LeadProductMatch >── Product      (Phase 3)

Activity  ──▶ morphs to Lead | Company | Contact
```

A `LeadProductMatch` reaches a lead two ways: directly (a manual pick, `lead_analysis_id` null) or
through the analysis run that produced it. That is why the row carries both `lead_id` and
`lead_analysis_id`.

- Search scopes (`scopeSearch`) and the lead filter set (`scopeFilter`) live on the models, so a
  controller never assembles a `where` chain by hand. Blank filter values are ignored, so a
  half-filled filter bar cannot accidentally empty a table.
- `HasActivities` (in `Models/Concerns`) provides the polymorphic relation **and** deletes the rows
  on `deleting`. A polymorphic relation carries no database foreign key, so without this the
  timeline rows would be orphaned.
- `Company::normaliseName()` strips punctuation and company suffixes so `Acme Medical, Inc.` and
  `acme medical inc` collide during import.

---

## 4. Database

Six domain tables plus Laravel's own `users` / `cache` / `jobs` / `sessions`.

```
companies                      products
  id                             id
  name (indexed)                 name (unique)
  website                        category
  country · state · city         short_description
  industry · company_type        detailed_description
  description                    target_customer      ┐ newline-separated
  specialties                    target_specialty     │ text; split for
  products_services              key_features         ┘ display
  notes                          value_proposition
  timestamps                     sales_notes
      │                          active
      │                          timestamps
      ├──────────┐                    │
      ▼          ▼                    │
contacts       leads ─────────────────┤
  id             id                   │
  company_id ──┐ company_id ──┐       │
  first_name   │ contact_id ──┘       │
  last_name    │ lead_source          │
  job_title    │ lead_status          │
  department   │ priority             │
  email        │ assigned_to          │
  phone        │ notes                │
  linkedin_url │ timestamps           │
  country·city │      │               │
  notes        │      ▼               ▼
  timestamps   │  lead_product_matches
               │    id
               │    lead_id      ──▶ cascade on delete
               │    product_id   ──▶ cascade on delete
               │    recommendation_type   ← Manual in Phase 1
               │    confidence_score      ← null in Phase 1
               │    reason · notes
               │    timestamps
               │    unique(lead_id, product_id)
               │
           activities
             id
             subject_type + subject_id   (polymorphic)
             type · title · body
             occurred_at
             timestamps
```

### Design decisions

**Statuses stored as strings, not database enums.** SQLite has no native enum, and strings port to
PostgreSQL with no type migration. The PHP enum casts on the models are what actually guard values.

**Nullable foreign keys with `nullOnDelete`.** Deleting a company keeps its contacts and leads —
you lose the organisation record, not the pipeline. Sales data is worth more than referential tidiness.

**`lead_product_matches` grew into the AI recommendation row.** Phase 1 left
`recommendation_type` and `confidence_score` in place unused; Phase 3 filled them and added
`priority`, `status`, `evidence`, `sales_angle`, `suggested_use_case`, `module`,
`raw_confidence_score` and `lead_analysis_id`.

The one breaking change was dropping `unique(lead_id, product_id)`. It was right when every match
was hand-made, and wrong once analyses are kept as history: two runs commonly recommend the same
product, and the second insert would have failed, silently discarding the newer reasoning.
"Already attached" is now enforced in the form request as "already an *active* opportunity"
instead — so a previously rejected suggestion does not block adding that product deliberately.

**`lead_analyses` holds the run, not the row.** Company summary, business opportunity, products to
avoid, missing information and next action belong to the analysis as a whole; duplicating them onto
every recommendation would be wasteful and ambiguous. History falls out naturally — a re-analysis
inserts a new run rather than updating an old one, and nothing is ever overwritten.

**`activities` is polymorphic.** One table and one Vue component serve leads, companies and
contacts, and Phase 4's email/follow-up entries need no new table.

### PostgreSQL portability

No raw SQLite SQL, no `PRAGMA`, no SQLite-only column types. All schema through migrations, all
queries through Eloquent. Switching is a `.env` change plus `php artisan migrate`.

The one thing to change: the `like` in `scopeSearch` is case-insensitive on SQLite but
case-sensitive on PostgreSQL, where it should become `ilike`.

---

## 5. Local AI layer

**Built in Phase 2.** The features that *use* it are Phase 3.

```
Vue page  --> Controller --> AIServiceInterface (interface)
                                     ^
                                     |  bound in AiServiceProvider
                                OllamaAIService
                                     |  HTTP, localhost only, LocalEndpointGuard checked
                             http://localhost:11434
                                     |
                            local LLM (qwen3 / llama3 / phi3 / ...)
```

### Files

| File | Role |
| --- | --- |
| `Services/AI/AIServiceInterface.php` | The seam. Everything depends on this |
| `Services/AI/OllamaAIService.php` | The only class that knows Ollama exists |
| `Services/AI/AiResult.php` | Immutable result of one completion |
| `Services/AI/AiStatus.php` | Connection + model snapshot for the UI |
| `Services/AI/AiSettings.php` | Effective settings; **the security boundary** |
| `Services/AI/LocalEndpointGuard.php` | Refuses a non-local endpoint |
| `Services/AI/StructuredResponseParser.php` | JSON recovery + validation |
| `Services/AI/AiRequestLogger.php` | Writes the local `ai_requests` log |
| `Services/AI/PromptTemplate.php` | Immutable prompt with substitution |
| `Services/AI/PromptLibrary.php` | Where prompts live |
| `Services/AI/Exceptions/*` | One per failure mode, each with a user-safe message |
| `Providers/AiServiceProvider.php` | The one place the implementation is chosen |
| `config/ai.php` | Provider, endpoint, defaults, limits, allowed hosts |

### The interface

```php
generate(string|PromptTemplate $prompt, AiRequestType $type, array $options): AiResult;
generateStructured(string|PromptTemplate $prompt, array $schema, AiRequestType $type, array $options): AiResult;
isAvailable(): bool;                 // never throws
getModels(): array;                  // installed models, from Ollama's own API
hasModel(?string $model): bool;
status(): AiStatus;                  // never throws
ping(): AiResult;                    // the Test AI Connection round trip
provider(): string;  model(): string;  endpoint(): string;
```

Two methods deliberately never throw - `isAvailable()` and `status()` - because the UI has to be able
to render "not connected" without an error page. Everything else throws an `AiException` subclass,
never a raw transport error.

### Why an interface at all

Callers never learn Ollama's HTTP API. That buys three things:

1. Swapping the local runtime, or the model, touches one class.
2. Tests bind a fake - no model needed in CI, and the suite passes with Ollama absent.
3. `status()` gives the UI a real answer instead of hanging on a dead port.

### Error handling

Every failure is a typed exception carrying **two** messages: `getMessage()` (technical, goes to the
local log) and `userMessage()` (plain language, goes to the browser). A stack trace never reaches the
UI.

| Exception | Cause | Log status |
| --- | --- | --- |
| `AiUnavailableException` | Ollama not installed / not running / wrong port | `unavailable` |
| `ModelUnavailableException` | model not pulled | `model_missing` |
| `AiTimeoutException` | model exceeded the timeout | `timeout` |
| `InvalidAiResponseException` | non-JSON body, HTTP error, empty completion, unparseable structured output | `invalid_response` |
| `PromptTooLargeException` | prompt over the character limit - rejected before sending | `rejected` |
| `InsecureEndpointException` | endpoint is not local - **blocked** | `rejected` |

Laravel raises `ConnectionException` for both a refused connection and a timeout;
`OllamaAIService::isTimeout()` inspects the message to separate them, because the right advice
differs ("start Ollama" vs "raise the timeout / use a smaller model").

### Structured output

Three layers, most reliable first:

1. **Ask properly.** Ollama's `format` parameter constrains generation - a JSON Schema when the
   caller supplies one, otherwise `"json"`.
2. **Reinforce.** `PromptLibrary::JSON_INSTRUCTION` is appended to the system prompt, for models
   that honour `format` only loosely.
3. **Recover.** `StructuredResponseParser` tries the whole string, then strips a ```json fence,
   then extracts the outermost balanced `{...}`.

That third step scans braces while tracking string state and escapes - not a regex - so a `}` inside
a string value cannot terminate the object early. If nothing decodes, or a `required` key is missing,
it throws. It never returns a partial structure: a half-parsed product recommendation is worse than
an honest failure.

### The security boundary

The endpoint is the whole privacy story, so it is protected in three independent ways:

1. **`AiSettings` has no endpoint setter.** `endpoint()` reads `config('ai.ollama.url')`, i.e. `.env`.
   `WRITABLE_KEYS` contains only `model`, `temperature`, `timeout`, `max_tokens`, `system_prompt`,
   and `save()` silently discards anything else - so a crafted POST cannot smuggle one in.
2. **`LocalEndpointGuard` runs before every request**, including before the availability probe, and
   throws if the host is not in `config('ai.allowed_hosts')`.
3. **Tests assert it**, across the source tree and by posting overrides at the settings form.

`AiServiceProvider::boot()` additionally logs a warning if `OLLAMA_URL` is non-local. It warns rather
than throws: taking down the whole CRM over an AI setting you might not be using is the wrong trade -
the guard is what actually blocks the call.

### Streaming

Not implemented. Requests send `"stream": false` and all completions funnel through one private
`run()` method, so a streaming variant is a new method rather than a rework of callers.

## 5b. Product recommendation (Phase 3)

Built on the Phase 2 AI layer without changing it. `AiProductMatcher` implements the
`App\Contracts\Ai\ProductMatcher` interface declared in Phase 1, and depends on
`AIServiceInterface` — so it does not know Ollama exists.

```
Lead + Company + Contact (database)
        |
        +--> LeadContextBuilder      renders the record; blanks become "(not recorded)"
        +--> ProductContextBuilder   renders the ACTIVE portfolio from `products`
        |
        v
PromptLibrary::productRecommendation()
        |
        v
AIServiceInterface::generateStructured(schema, type: ProductRecommendation, lead_id)
        |
        v
RecommendationValidator   drops invented products, coerces malformed fields
ConfidenceCalibrator      caps confidence at what the record supports
        |
        v
lead_analyses  +  lead_product_matches   (status: Suggested)
```

### Two guards that carry the design

**RecommendationValidator.** The schema constrains shape; this constrains meaning. A model
references products that do not exist, attaches the wrong id to the right name, returns confidence
as `92` or `"high"`, names a module the product lacks, and picks a "primary" it never recommended.
Each of those is handled explicitly, and what was discarded is stored on the analysis as a warning
so the filtering is visible rather than silent. It never guesses: a product name matching nothing is
rejected outright.

**ConfidenceCalibrator.** Models score the product, not the evidence — a record containing only a
company name still gets 0.9. So the score is additionally capped from how complete the record is
(`LeadContextBuilder::evidenceStrength()`), weighted toward the fields a match can actually be
argued from. Calibration only ever lowers, never raises, and the model's original number is kept in
`raw_confidence_score` so the UI can show both.

### Modules

A recommendation may name a capability inside a product — *Knee Planning* within the 3dsurgical
Platform. Validated against that product's own `key_features`, so the module list is never
hard-coded and cannot be invented.

### Why the unique constraint had to go

Phase 1 had `unique(lead_id, product_id)` on `lead_product_matches`. Correct then; wrong once
analyses are kept as history, because two runs will very often recommend the same product and the
second insert would fail — silently losing the newer reasoning. It is now a plain index, and
"already attached" is enforced in `StoreLeadProductMatchRequest` as "already an *active*
opportunity", so a previously rejected suggestion does not block adding the product deliberately.

### Human approval

`recommendation_type` records provenance (Manual / AI Primary / AI Secondary); `status` records the
human decision (Suggested / Accepted / Rejected / Archived). AI output is always created
`Suggested`. Nothing in the application promotes it, and analysis never touches the lead's status,
priority or stage — asserted by test.

See [PRODUCT_INTELLIGENCE.md](PRODUCT_INTELLIGENCE.md) for the prompt, the confidence scale and the
full list of what the validator catches.

## 6. Data flow

### Reading a page — the lead list

```
GET /leads?status=Qualified&priority=High
   │
   ├─▶ LeadController@index
   │      $filters = request()->only([...])
   │      Lead::with(company, contact, productMatches.product)
   │          ->withCount('productMatches')
   │          ->search($filters['search'])      ← model scope
   │          ->filter($filters)                ← model scope
   │          ->latest('updated_at')->paginate(15)->withQueryString()
   │
   ├─▶ LeadResource::collection($leads)
   │      flattens enums to { lead_status, status_color, priority, priority_color }
   │      so Vue needs no mapping logic
   │
   ├─▶ Inertia::render('Leads/Index', [ leads, filters, filterOptions, options ])
   │
   └─▶ Leads/Index.vue
          defineProps<{ leads: Paginated<Lead>, ... }>()   ← typed from types/models.ts
          DataTable + Badge + FilterBar + Pagination
```

Eager loading is decided in the controller and expressed in the resource with `whenLoaded` /
`whenCounted`, so one resource serves both list and detail views without firing N+1 queries for
fields a given page does not show.

### Writing — creating a lead

```
LeadFormModal.vue  useForm({...}).post('/leads')
   │
   ├─▶ CSRF middleware · Inertia middleware
   ├─▶ StoreLeadRequest
   │      field rules + "company or contact required"
   │      + "contact must belong to this company"
   │      on failure → 422, errors land in form.errors, modal stays open
   │
   ├─▶ LeadController@store
   │      Lead::create(validated)
   │      Activity::record($lead, StatusChange, 'Lead created')
   │
   └─▶ to_route('leads.show', $lead)->with('success', 'Lead created.')
          │
          ├─▶ HandleInertiaRequests shares flash.success
          └─▶ AppLayout watches it → ToastHub renders the toast
```

### CSV import — two phases

```
POST /import/preview          POST /import
   CsvImporter::analyse()        CsvImporter::import()
   ├ read headers → map          ├ analyse() again  ← re-parsed, not trusted from the client
   ├ per-row validate            ├ DB::transaction
   ├ duplicate check             │   ├ skip invalid rows always
   │   vs database               │   ├ skip duplicates unless opted in
   │   vs earlier rows           │   ├ resolveCompany  → reuse or create
   └ WRITES NOTHING              │   ├ resolveContact  → reuse by email or create
        │                        │   └ create Lead
        ▼                        └ redirect with a summary
   re-renders Import/Index
   with the preview prop
```

The uploaded file is read in-process and never persisted. The preview is a page prop, not session
flash — a 5000-row analysis has no business in a session store.

### An AI request - the Playground

```
AiPlayground.vue   useForm({prompt, model, temperature, structured}).post('/settings/ai/playground')
   |
   |-> RunAiPromptRequest          field rules + prompt size limit
   |
   |-> AiPlaygroundController@run
   |      guards a per-run model override against getModels()
   |      PromptLibrary::general($prompt)  --> PromptTemplate (+ system prompt)
   |
   |-> AIServiceInterface::generate() / generateStructured()
   |      `-> OllamaAIService::run()
   |            LocalEndpointGuard::assertLocal()      <-- blocks non-local
   |            prompt size check
   |            POST http://localhost:11434/api/generate  {stream: false, format?, options}
   |            parse / validate  (StructuredResponseParser when structured)
   |            AiRequestLogger  --> ai_requests        <-- success AND failure
   |
   |-- success --> Inertia::render('Settings/AiPlayground', ['result' => ...])
   |                 rendered, not flashed: a completion can be tens of kilobytes
   |
   `-- AiException --> back()->withInput()->with('error', $e->userMessage())
                         the attempt is already logged; the user sees plain language
```

Note the ordering: the guard and the size check run *before* the transport, so a blocked or oversized
request sends zero bytes - and is still logged, as `rejected`.

---

## 7. Privacy architecture

Privacy here is structural, not a policy statement.

| Guarantee | How it is enforced |
| --- | --- |
| CRM data never leaves the machine | One SQLite file; no remote database configured anywhere |
| AI inference is local | `AIServiceInterface` is bound only to `OllamaAIService`, which targets `config('ai.ollama.url')` |
| The AI endpoint cannot become remote | `LocalEndpointGuard` throws before any request if the host is not local; the endpoint is env-only and absent from `AiSettings::WRITABLE_KEYS`; the settings form has no rule for it |
| No cloud AI | No AI SDK in `composer.json` or `package.json`; a test fails the build on any cloud AI hostname in `app/`, `resources/js/`, `routes/`, `config/`; `AI_PROVIDER` accepts only `ollama` and throws otherwise |
| No API keys | None exist. There is nothing to leak, and nothing to bill |
| The AI log stays local | `AiRequestLogger` writes only to the local SQLite file. No queue, no shipper, no remote sink |
| No telemetry or analytics | No such package installed; both manifests are auditable |
| No third-party assets | The scaffold's Bunny Fonts webfont fetch was **removed**; system font stack instead. All icons are inlined SVG |
| CRM pages make no HTTP calls | `ApplicationSmokeTest` browses them under `Http::preventStrayRequests()` and asserts `Http::assertNothingSent()` |
| AI pages call only localhost | The same suite records every request the AI pages make and asserts each URL passes `LocalEndpointGuard::isLocal()` |
| Localhost only | `php artisan serve` binds `127.0.0.1`; no host configuration exposes it |
| Uploads not retained | CSV files are parsed in memory and never written to disk |
| Prompts are bounded | Rejected above `AI_MAX_PROMPT_CHARS` before any request; responses capped before storage |
| No shell execution | A missing model produces the `ollama pull` command as *text*. The app never runs it |
| External links cannot leak referrers | `rel="noopener noreferrer nofollow"`, plus a page-level `referrer: no-referrer` meta |
| Not indexable | `robots: noindex, nofollow` |

### What is NOT claimed

Deliberately not asserted anywhere in this project: that it is "100% secure" or that privacy is
"guaranteed". Neither is a claim software can make about the machine it runs on. The accurate
statement is:

> AI inference is configured to run locally through Ollama, and the application does not
> intentionally send AI requests to external cloud services.

Outside this application's control, and worth knowing: Ollama may check for its own updates (that is
Ollama, and it does not include your prompts); any backup or file-sync tool watching the project
folder will copy `database/database.sqlite` to wherever it syncs; and disk encryption is your
machine's business. [docs/LOCAL_AI.md §4](LOCAL_AI.md) covers this in full.

### Attack surface

There is no authentication, by design — a single-user local application. The practical surface is
whoever can reach `127.0.0.1:8000` on your machine, which is you. If that ever changes — a shared
machine, or binding to a LAN address — authentication becomes a real requirement, not an optional
extra.

Phase 2 adds one component to that surface: Ollama, listening on `127.0.0.1:11434`. It is reachable
by anything already running as you on this machine, exactly like the app itself. It holds no CRM
data — only the model — but the same reasoning applies: if you ever bind either service to a LAN
address, authentication stops being optional.

### Backups

`database/database.sqlite` is the entire dataset — CRM records, AI settings and the AI request log.
Copy the file. There is no automated backup and no soft deletes, so a deletion is permanent.

Conversely: if that file sits inside a cloud-synced folder, the whole dataset is being copied to that
provider. Worth checking where the project actually lives.
