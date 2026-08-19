# Local AI

How Shvar AI Copilot uses a language model, what that means for your data, and what to do when it
breaks.

---

## 1. What Ollama is

[Ollama](https://ollama.com) is a program that runs language models on your own computer. You
install it, pull a model once, and it exposes a small HTTP API on `http://localhost:11434` that other
local programs can call.

It is not a service you sign up for. There is no account, no API key, and no per-token cost. The
model file sits on your disk and the computation happens on your CPU or GPU.

## 2. Why it is being used

Because the alternative is incompatible with the point of this application.

A cloud model — OpenAI, Anthropic, Gemini, Azure — requires sending the prompt to a third party. For
Shvar AI Copilot the prompt *is* the sensitive material: company profiles, contact names and titles,
lead notes, deal stage, your own pricing and positioning. Uploading a pipeline to another company's
infrastructure to get a draft email back is not a trade worth making for a private CRM.

Ollama removes the trade. The model runs where the data already is.

The cost is honest: a local model on a laptop is slower than a hosted frontier model, and a small
local model is less capable. Phase 2 is built so you can change model in one dropdown when that
matters.

## 3. How data flows

```
   Your data (companies, contacts, leads, products)
        |
        v
   Laravel  ──  builds the prompt from a PromptTemplate
        |
        |   LocalEndpointGuard::assertLocal()   <-- blocks anything non-local
        v
   HTTP POST http://localhost:11434/api/generate
        |
        v
   Ollama (a process on this machine)
        |
        v
   Local LLM (a model file on your disk)
        |
        v
   Ollama's JSON response
        |
        v
   Laravel  ──  parses / validates, writes to ai_requests
        |
        v
   Database (local SQLite file)  +  UI
```

There is deliberately no path of the form:

```
   Laravel  ->  Internet  ->  AI API        <-- does not exist
```

Not "disabled". Not "off by default". Not present.

### The one class that talks to Ollama

`app/Services/AI/OllamaAIService.php` is the only file in the codebase that knows Ollama exists.
Everything else depends on `AIServiceInterface`:

```
Shvar AI Copilot
     v
AIServiceInterface        <- controllers and services depend on this
     v
OllamaAIService
     v
Ollama localhost API
     v
Local LLM
```

That is what makes the runtime swappable — and what makes the privacy property auditable, because
there is exactly one place where an outbound call could originate.

## 4. What data leaves your computer

**Nothing, as a result of using the AI features.**

To be precise about what that claim covers:

| Data | Leaves the machine? |
| --- | --- |
| Prompts you type in the Playground | No — sent to `localhost:11434` |
| Company, contact, lead, product records | No — never transmitted anywhere |
| Model responses | No |
| The AI request log (`ai_requests`) | No — written to your local SQLite file only |
| Your settings (model, temperature, timeout) | No — local database |
| Usage statistics, telemetry, crash reports | None are collected. No such code exists |

Requests to `http://localhost:11434` do not traverse a network interface that leaves the machine —
they go over the loopback interface.

### What this does *not* claim

This document does not claim the application is "100% secure" or that your data is "guaranteed
private". No application can promise that about the computer it runs on. The accurate statement is:

> AI inference is configured to run locally through Ollama, and the application does not
> intentionally send AI requests to external cloud services.

Things outside this application's control still apply, and are worth knowing:

- **Ollama itself** may check for its own updates. That is Ollama's behaviour, not this app's.
  It does not include your prompts.
- **Your operating system, antivirus, and any backup or sync tool** can read
  `database/database.sqlite` like any other file. If that file is inside a synced folder
  (OneDrive, Dropbox, Google Drive), your CRM data is being copied to that provider — by that
  tool, not by this app. Check where the project lives.
- **Disk encryption** is your machine's business. If the laptop is stolen and the disk is
  unencrypted, the database is readable.
- **`php artisan serve` binds to `127.0.0.1`**, so the app is not reachable from your network. If
  you deliberately change that, add authentication first — there is none.

### How the boundary is enforced in code

Three mechanisms, not one:

1. **`LocalEndpointGuard`** runs before every request and throws `InsecureEndpointException` if the
   configured host is not in `config('ai.allowed_hosts')` (`localhost`, `127.0.0.1`, `::1`, …).
   A misconfigured `OLLAMA_URL` fails loudly instead of quietly forwarding data.
2. **The endpoint is not user-writable.** `OLLAMA_URL` comes from `.env` only. It is not in
   `AiSettings::WRITABLE_KEYS`, the settings form has no rule for it, and `AiSettings::save()`
   discards unknown keys. The UI renders it read-only. So no request from the browser can repoint
   AI traffic at a remote host.
3. **Tests assert it.** `ApplicationSmokeTest` fails the build if a cloud AI hostname appears
   anywhere in `app/`, `resources/js/`, `routes/` or `config/`, and separately records every HTTP
   request the AI pages make and asserts each one went to a local address.
   `AiSettingsTest` posts every spelling of an endpoint override at the settings form and asserts
   the endpoint is unchanged and that a remote endpoint sends zero bytes.

## 5. How to change models

### List what you have

```powershell
ollama list
```

Or open **Settings → AI** — the *Installed Models* panel reads the same list from Ollama's local API.

### Pull a different model

```powershell
ollama pull llama3
ollama pull phi3:mini
ollama pull qwen3:8b
```

The application never downloads a model for you, and never runs a shell command on your behalf. If a
model is missing it tells you the exact command and you run it yourself.

### Switch the active model

**Settings → AI → Model**, then Save. Stored in your local database, so it survives a restart and
overrides `OLLAMA_MODEL` in `.env`.

`.env` remains the default for a fresh install:

```dotenv
OLLAMA_MODEL=qwen3:4b
```

Bare names work: configuring `llama3` matches an installed `llama3:latest`.

### Choosing a model

Bigger is more capable and slower. For the Phase 3 work ahead (company analysis, product
recommendation, email drafting), two properties matter more than raw benchmark scores:

- **It must follow instructions.** The system prompt tells it not to invent facts. A model that
  ignores that will confidently state a regulatory clearance that does not exist, which is a serious
  problem in medical technology sales — not a cosmetic one.
- **It must produce clean JSON.** Phase 3's product recommendation depends on structured output.
  Very small models fence their JSON, prefix it with chatter, or drift from the schema. The parser
  recovers from the first two; it cannot fix a model that will not comply.

A ~4B instruction-tuned model is a reasonable starting point on a laptop, and a ~7–8B one if you
have the VRAM for it. If responses are too slow, drop a size and raise the timeout; if JSON mode is
unreliable, go up a size.

Be aware of the third axis: **reasoning vs non-reasoning**. A reasoning model (qwen3, deepseek-r1)
is more careful but pays a large latency tax on CPU, because it generates its chain of thought
before answering. A non-reasoning model of the same size answers far faster, and is usually weaker
at holding to a strict instruction such as "do not invent facts".

## 6. Troubleshooting

### Ollama runs, but every request fails with `exit status 0xe06d7363`

`llama-server` is crashing. The cause is in `%LOCALAPPDATA%\Ollama\server.log`; the
line to look for is:

```
ggml_vulkan: device Vulkan0 does not support 16-bit storage.
```

Ollama picked the **Vulkan** backend for your GPU, and that GPU's Vulkan driver cannot support it.
Ollama normally prefers CUDA, and falls back to Vulkan when the NVIDIA driver is too old for the
CUDA runtime it ships (CUDA 12 needs driver 525+, and Ollama also ships CUDA 13).

Two fixes:

**a) Skip the GPU** — immediate, no download:

```powershell
[Environment]::SetEnvironmentVariable('OLLAMA_VULKAN','0','User')
```

Then fully quit Ollama (system tray → Quit) and start it again. Inference moves to the CPU, which is
slower but works. This persists across reboots.

**b) Update the NVIDIA driver** so CUDA becomes available. Check your current version with
`nvidia-smi`. If the card is Pascal-era or newer it is still supported by NVIDIA's current
production-branch driver, and updating usually restores GPU acceleration — a large speed-up, provided
the model fits in VRAM.

### Answers are slow, or a reasoning model "thinks" for ages

Reasoning models (qwen3, deepseek-r1, and similar) generate a chain of thought before answering. On a
CPU that is often the majority of the wait: a one-sentence answer can cost hundreds of tokens of
internal reasoning first.

Options, cheapest first:

- **Raise `AI_TIMEOUT`.** On CPU, 120s is optimistic. 300 is a saner floor.
- **Use structured (JSON) mode** where you can — Shvar AI Copilot suppresses reasoning for structured
  requests, which is both faster and required for correctness (see below).
- **Switch to a non-reasoning model** — `llama3.2:3b`, `qwen2.5:3b`, `phi3:mini`. Much faster,
  less capable.
- **Get the GPU working** (above). This is the big one if the model fits in VRAM.

### Structured output returns nothing on a reasoning model

Handled automatically, but worth understanding if you write your own calls.

With a reasoning model, asking for JSON *without* suppressing reasoning makes Ollama route the JSON
into a separate `thinking` field and return an **empty** `response`. Verified on qwen3:4b /
Ollama 0.32:

| Request | Result |
| --- | --- |
| `format: json` + `think: false` | clean JSON, fast |
| `format: json`, reasoning left on | JSON goes to `thinking`, `response` is empty |
| plain text, reasoning left on | clean answer, reasoning kept aside |
| plain text + `think: false` | reasoning leaks into the answer |

`OllamaAIService` therefore sends `think: false` for every structured request and leaves it alone for
text. Override per call with the `think` option if you need to.

### A long request dies with "Maximum execution time of 30 seconds exceeded"

PHP's own `max_execution_time` (commonly 30s in `php.ini`) will kill a request the application is
still legitimately waiting on, which makes `AI_TIMEOUT` meaningless and produces a fatal error
instead of a friendly one.

`OllamaAIService` calls `set_time_limit()` with the configured AI timeout plus a margin, so this is
handled. If you see it anyway, `set_time_limit` is probably disabled in your PHP configuration —
raise `max_execution_time` in `php.ini` instead.

### "Unable to connect to Ollama at http://localhost:11434"

Ollama is not running, or not installed.

```powershell
# Is it installed?
ollama --version

# Is it listening?
curl http://localhost:11434/api/version
```

On Windows, Ollama runs as a background app once launched — look for it in the system tray. Launch it
from the Start menu, then press **Re-check** in Settings → AI.

If `ollama --version` fails, it is not installed: get it from [ollama.com](https://ollama.com), then
pull a model.

If something else occupies port 11434, either stop that program or point `OLLAMA_URL` at the port
Ollama actually uses. It must still be a local address.

### "Model "x" is not installed"

Ollama is running but that model has not been pulled.

```powershell
ollama pull qwen3:8b
```

Then **Re-check**. Or pick an already-installed model from the dropdown.

### "Ollama is running but no models are installed"

`ollama list` is empty. Pull one. The application will not do it for you — downloads are gigabytes
and that is your decision to make.

### "The local model did not respond within N seconds"

Usually the first request after a restart: the model is being loaded into RAM, which can take a
while for a large model on a slow disk.

- Try again — the second request is much faster while the model stays loaded.
- Raise **Timeout** in Settings → AI (max 600s).
- Use a smaller model.
- Check whether something else is saturating the CPU.

### "The local model did not return valid structured data"

Asked for JSON, got something unparseable. The parser already strips code fences and surrounding
commentary, so this means the output was not JSON at all.

- Lower the temperature (0.1–0.3 for structured output).
- Use a larger model — small ones comply poorly.
- Look at **Settings → AI Logs**: the entry stores exactly what the model returned.

### "That prompt is too long"

Rejected before sending, to stop an accidental paste of a whole document from stalling the machine.
Shorten it, or raise `AI_MAX_PROMPT_CHARS` in `.env`.

### "The configured AI endpoint is not on this machine, so the request was blocked"

`OLLAMA_URL` is not a local address. This is the guard working as intended. Fix `.env`:

```dotenv
OLLAMA_URL=http://localhost:11434
```

Then `php artisan config:clear`.

### Responses are very slow

Expected on CPU-only hardware; a local model is doing real work. In rough order of impact: use a
smaller model, ensure your GPU is actually being used (`ollama ps` shows placement), close other
heavy applications, and cap **Max tokens** so the model stops sooner.

### Nothing appears in AI Logs

Either `AI_LOGGING_ENABLED=false` in `.env`, or the request failed validation before reaching the
service — an empty prompt or an uninstalled model override never reaches the model, so there is
nothing to log. Requests that *do* reach the model are always logged, success or failure.

### Reading the log

**Settings → AI Logs** lists every attempt with status, model and execution time. Click a row for the
full prompt, the full response, and — on a failure — the technical error message. That message is
kept out of the UI everywhere else on purpose; the friendly message is what you see in a toast.

---

## 7. Reference

| Setting | Where | Notes |
| --- | --- | --- |
| `AI_PROVIDER` | `.env` | `ollama`. Anything else throws at boot. |
| `OLLAMA_URL` | `.env` **only** | Local hosts only. Never writable from the UI. |
| `OLLAMA_MODEL` | `.env`, overridable in Settings | No assumption that it is installed. |
| `AI_TEMPERATURE` | `.env`, overridable in Settings | 0.0–2.0. |
| `AI_TIMEOUT` | `.env`, overridable in Settings | Seconds, 5–600. |
| `AI_MAX_TOKENS` | `.env`, overridable in Settings | Blank = model default. |
| `AI_PROBE_TIMEOUT` | `.env` | Short timeout for status checks. |
| `AI_MAX_PROMPT_CHARS` | `.env` | Pre-flight prompt size limit. |
| `AI_LOGGING_ENABLED` | `.env` | Local log on/off. |
| System prompt | Settings → AI | Blank = the shipped default. |

| Ollama endpoint | Used for |
| --- | --- |
| `GET /api/version` | Version shown in Settings |
| `GET /api/tags` | Installed model list, availability probe |
| `POST /api/generate` | Completions (`stream: false`) |

Nothing else is called. No model is ever pulled or deleted by this application.

## 8. Phase 2 scope

Implemented: the AI service layer, Ollama transport, model discovery, connection testing, structured
output, prompt templates, local request logging, and the three Settings screens.

The only AI request type Phase 2 produces is `general`, from the Playground. Company analysis,
product recommendation, email generation, follow-up generation and local RAG are Phase 3 — their
enum cases exist and are marked "Phase 3" in the UI, but nothing generates them.
