# Company Research

Filling in a company record by reading its website, rather than by asking a model what it remembers.

> **Findings are proposals. Nothing is written to a company record until you tick a field and save it.**

---

## 1. Why it reads the website

The obvious way to build this feature is to ask the model what it knows about a company. That was
tested, and it does not work.

Asked about **Materialise NV** — a company that genuinely exists — qwen3:4b produced a broadly
correct summary and then listed **"3D Slicer"** as one of their products. 3D Slicer is an
open-source project from Harvard. The other three "products" were invented names. It also produced
a confident line about regulatory compliance with no source.

Asked about **Verixa Craniofacial Systems** — a company invented for the test, on a `.example`
domain that cannot resolve — it produced a complete profile: industry, company type, description,
three specialties, three products. It never said it did not know the company.

For medical technology sales that is not a rough edge. Walking into a meeting citing a product the
prospect does not make is a credibility problem.

So the feature does not ask the model to recall. It **fetches the page and asks the model to read
it**, which is a different and much easier task.

## 2. How it works

```
Company name + website  (you type these)
        |
        v
PublicUrlGuard        rejects private, loopback and metadata addresses
        v
WebsiteFetcher        ONE outbound request; redirects followed manually,
        |             each hop re-validated
        v
HtmlTextExtractor     strips nav, cookie banners, scripts; keeps title,
        |             meta description and body text
        v
PromptLibrary::companyResearch()
        v
AIServiceInterface::generateStructured()  ->  Ollama on localhost
        v
CompanyResearchValidator   checks every quote is really on the page
        v
company_analyses      findings await your review
        v
You tick fields  ->  applied to the company record
```

## 3. The evidence check

This is the part that makes the rule enforceable rather than advisory.

Every finding must include `evidence`: a verbatim quote from the page. `CompanyResearchValidator`
then goes and looks for that quote in the text the model was given. If it is not there, the finding
is discarded — however plausible it sounds.

A model answering from memory produces evidence it cannot source, and this catches exactly that.

Seen in a real run against materialise.com:

```
not stated on the page: country, state, city

discarded by the validator:
  - Dropped Country - no supporting quote from the page.
  - Dropped State / region - no supporting quote from the page.
  - Dropped City - no supporting quote from the page.
```

Materialise **is** Belgian, and the model almost certainly knew that. It tried to fill the field
anyway. The page never said so, so it was dropped.

Matching is tolerant of whitespace, case, curly quotes and punctuation — a model re-wraps a quote
without meaning to fabricate. It is **not** tolerant of different words: a quote whose words are not
on the page fails all three passes.

## 4. What gets extracted

| Field | Notes |
| --- | --- |
| Industry | |
| Company type | manufacturer, hospital, university, software vendor, … |
| Description | 2-3 sentences drawn from the page |
| Specialties | stored newline-separated |
| Products / services | stored newline-separated |
| Country, state, city | only when the page states them |

Deliberately excluded: `name` and `website` (you typed them) and `notes` (yours, not the model's).

Anything the page does not establish is reported under **Not stated on the page** rather than
guessed. The prompt explicitly forbids inferring a country from a language, a domain suffix or a
phone number.

## 5. Privacy — what changed

**This is the one feature in the application that makes an outbound request.** Everything else,
including all AI inference, stays on your machine.

| | |
| --- | --- |
| What is sent | An HTTP GET to the URL you typed. Nothing else. |
| What is **not** sent | Your CRM data, leads, contacts, notes, product portfolio, prompts, or anything about you beyond a normal web request. |
| Who sees it | The website you named — the same as if you had opened it in a browser. |
| Where the AI runs | Still Ollama on localhost. The page text never leaves your machine. |

The request identifies itself honestly rather than impersonating a browser:

```
User-Agent: ShvarAICopilot/1.0 (private CRM research; one page per lookup)
```

**To switch it off entirely**, restoring the zero-outbound-request posture:

```dotenv
RESEARCH_FETCH_ENABLED=false
```

The button then explains it is disabled instead of failing.

## 6. Security: why the URL is guarded

The URL comes from a form, so it is untrusted input pointed at the network. Without a guard,
"Research Company" is a request-forgery primitive. Someone — or a mistyped address — could aim it at:

- `http://localhost:11434` — **your own Ollama**, read back through the UI
- `http://169.254.169.254` — cloud metadata, which on a hosted box hands out credentials
- `http://192.168.1.1` — your router's admin page
- `http://127.0.0.1:8000` — this application

`PublicUrlGuard` is the exact mirror of `LocalEndpointGuard`. That one *requires* the AI endpoint to
be local; this one *forbids* the research target from being local, because the threat models are
opposite.

Defence is at the **IP** level, not the hostname. A string check is trivially bypassed —
`http://127.0.0.1.nip.io` looks like an ordinary domain and resolves to loopback. Every hostname is
resolved and **every** resulting address checked, and again after each redirect, because a public URL
that 302s to an internal address is the standard way past a naive check.

Blocked: loopback, RFC 1918 private ranges, link-local, carrier-grade NAT, IPv6 unique-local and
loopback, multicast and reserved space. Non-HTTP schemes and embedded credentials are rejected
outright.

Error messages are deliberately vague to the user — "resolves to a private or internal network" —
because naming the address would confirm what exists on your network. The specific IP goes to the
local log only.

## 7. Using it

**Company page → Research from website.**

The website is what makes this work, so it is effectively required. If a company has none recorded
you can enter one for a single lookup without saving it to the record.

The result is a checklist. Each finding shows its value, its confidence, and the quote it came from.
Tick what you want and press save; unticked findings are discarded. Findings already saved are
marked and cannot be applied twice.

From the command line:

```powershell
php artisan ai:research 3                                    # an existing company
php artisan ai:research --url=acme-medical.com --name="Acme" # a one-off, record removed after
php artisan ai:research --url=acme-medical.com --keep        # keep the company
```

## 8. History

Every run is kept. Re-running adds an analysis rather than replacing one, so what a site said before
a redesign stays readable, along with which fields you accepted from it.

## 9. Limitations

1. **JavaScript-only sites look empty.** A plain fetch gets no rendered content from a React or
   Angular site with no server-side rendering. You get "the page loaded but contained almost no
   readable text" rather than invented data. Enter details by hand in that case.
2. **Some sites block automated visitors** and return 403. The message says so.
3. **Only the homepage and an About page** are read — at most two pages, to keep the prompt inside
   what a local model can handle on CPU.
4. **Speed.** Roughly 10 seconds to fetch and 2-3 minutes for the model, on CPU. See
   [LOCAL_AI.md](LOCAL_AI.md).
5. **A marketing homepage is marketing copy.** The extraction is faithful to the page; the page
   itself may be vague. "We empower sustainable innovation" extracts as exactly that.
6. **No search.** It reads the URL you give it. It does not find the company for you.
7. **TLS verification requires a CA bundle.** On Windows, PHP ships without one; `curl.cainfo` must
   point at a `cacert.pem` or every HTTPS fetch fails with "unable to get local issuer certificate".
   Certificate verification is never disabled to work around this.

## 10. What it does not do

No search engines, no LinkedIn, no directories, no crawling beyond the two named pages, no
JavaScript execution, no storing of the raw HTML. It fetches, reads, and forgets — only the
extracted findings are kept.
