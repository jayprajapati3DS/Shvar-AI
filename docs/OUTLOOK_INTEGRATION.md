# Outlook Integration

Sends through, and reads replies from, the Outlook desktop app already signed in
on this machine.

**No credentials. No SMTP AUTH. No OAuth. Nothing crosses the network from this
application** — COM is local IPC to a process on the same laptop, and Outlook
itself does the talking to Exchange exactly as it does when you use it by hand.

---

## 1. Why this instead of SMTP

| | SMTP | Outlook COM |
| --- | --- | --- |
| Credentials | password, stored encrypted | none |
| Microsoft 365 SMTP AUTH | must be enabled by an admin | not involved |
| MFA / app passwords | a real hurdle | not involved |
| Sent copy in your Sent Items | no | yes |
| Your Outlook signature and transport rules | no | yes |
| Reading replies | no | yes |
| Works on | anything | Windows + classic Outlook |

If SMTP AUTH is disabled on your tenant — which is Microsoft's default now —
this is the path that works.

---

## 2. Requirements

- **Windows.**
- **`com_dotnet` enabled** in `php.ini`. The DLL ships with PHP for Windows and
  is switched off by default:

  ```ini
  extension=com_dotnet
  ```

- **Classic Outlook**, with a mail profile. This matters: the **new Outlook**
  (the Store app, process name `olk`) exposes **no automation interface at all**.
  Both can be installed side by side; this drives the classic one.

Check it from Settings → Email, which reports the version, the account and the
folder counts.

### The Object Model Guard

Outlook gates some properties behind a security layer. On this machine
`Namespace.CurrentUser` returns `0x80004004 Operation aborted` outright, while
reading folders works fine.

`ComOutlookGateway` treats the account name as a nicety rather than a
requirement — it asks `Session.Accounts` first, which is not guarded, and falls
back to null. A blocked name never takes the connection down with it.

---

## 3. Sending

```dotenv
EMAIL_DRIVER=outlook
```

`OutlookEmailService` **does not send.** It creates the message in Outlook,
fully populated, saves it for a stable EntryID, and opens the compose window.
You press Send.

That changes what the statuses mean:

```
Approved  --hand to Outlook-->  Queued  --you press Send-->  Sent
```

The draft becomes **Queued**, not Sent, because at that moment nothing has been
sent — it is a window on your screen. Claiming otherwise would put a lie in the
activity timeline. `Queued` had existed on `EmailDraftStatus` since Phase 4 with
nothing setting it; this is what it was for.

`OutlookMailboxSync::reconcileQueued()` promotes it to Sent once Outlook reports
a `SentOn` timestamp — the only honest signal available. It runs on every reply
sync.

### The gates still apply

`send()` refuses, in order, checked inside the transport rather than trusted
from the caller:

1. **Not approved** → `EmailNotApprovedException`
2. **Not on the allowlist** → `RecipientNotAllowedException`. It applies here
   too: the message would be sitting in a window addressed to a real person, one
   keystroke from going out.
3. **Outlook unreachable** → a friendly error, and the draft is marked Failed.

---

## 4. Reading replies

**Only mail from CRM contacts is read.** `OutlookMailboxSync` owns that scope and
it is the narrowest part of the whole feature.

The address list is built from the `contacts` table and handed to the gateway,
which filters against it before touching a body. A real mailbox here holds ~5,800
messages; a sync of it imported **0**, because none of the CRM contacts had
written recently. That is the property working.

- Idempotent. `outlook_entry_id` is unique, so running it twice imports nothing
  twice.
- **Not on a timer.** It is a button. Reading someone's mail should be something
  they asked for and can see happening — a background poll quietly opening
  messages is a different thing from a button that reports what it did. A test
  asserts no schedule exists.
- Default window 30 days, hard cap 100 messages per run.
- A reply is matched to the draft it answers by Outlook's conversation id first,
  falling back to the most recent thing sent to that person. The match only ever
  attaches context; it never decides anything.

Deleting a reply removes the local copy. The message stays in Outlook.

---

## 5. Reading a reply with the local model

`ReplyClassifier` sends the reply text to Ollama on localhost — same as
everything else, nothing leaves the machine — and gets back a classification, a
summary, verified quotes, the asks, any dates mentioned, and a suggested
follow-up.

### The failure mode this guards against

Each AI feature here has its own. Product recommendation risks forcing a match;
company research risks answering from memory; email writing risks flattery.

**Reading a reply risks optimism.** A model asked "are they interested" finds
interest in *"thanks, not right now"*, because agreeing is what it was trained to
do. That is not harmless: an over-read reply produces a follow-up task, which
produces another email, to someone who already said no.

So the system prompt pushes the other way:

- *"We will keep this on file"*, *"perhaps later in the year"*, *"I will revert"*
  and *"let me check internally"* are named explicitly as **polite deferrals**,
  classified `Not now`. British and Indian business English is indirect and the
  model needs telling.
- `Interested` requires something concrete — a demo, a call, pricing, documents,
  or a yes. Curiosity is a `Question`.
- When genuinely unsure, `Unclear` is a correct answer and far more useful than a
  confident wrong one.

### Quotes are checked

Like `CompanyResearchValidator`, the quotes the model offers are verified against
the actual reply text. A quote it composed is exactly the kind of confident
detail that makes a wrong classification look justified.

### No follow-up after a refusal

`ReplyClassification::allowsFollowUp()` returns false for `Not interested` and
`Unsubscribe`, and `ReplyClassifier` checks it **before** looking at what the
model suggested. Structural, not a prompt instruction the model might ignore —
suggesting "chase them again" to someone who asked to stop is the one output
here that could do real damage.

The suggested wait is also floored by classification, so nobody gets a suggestion
to chase tomorrow someone who said "ask me next quarter".

---

## 6. Follow-up tasks

A task is a reminder with a date. **Nothing acts on it** — completing one does
not trigger an email, and an overdue one does not chase anybody. The moment a
to-do list can act on its own, "human approval is mandatory" stops being true.

`source` records whether it came from you or the model, and the UI labels AI
suggestions. Knowing which items came from a machine is the difference between a
to-do list you trust and one you stop reading.

**Dismissed** is separate from **Done** on purpose: "I did this" and "this was
never worth doing" are different facts, and collapsing them would make the AI's
suggestions look more useful than they were.

---

## 7. Testing

Everything above the COM boundary is covered against `FakeOutlookGateway`. No
test touches COM: that needs Windows, a registered classic Outlook, a MAPI
profile and a signed-in user, and a suite requiring all four would not run on a
colleague's laptop and would rot quietly.

`OutlookGatewayInterface` exists for exactly that reason.

---

## 8. The encoding bug worth knowing about

MAPI hands back **Windows-1252** for anything typed with a smart quote, an
en-dash, a non-breaking space or an accented name — which is most real business
email. Those bytes are not valid UTF-8, and the first thing that notices is
`json_encode` inside the HTTP client:

```
GuzzleHttp\Exception\InvalidArgumentException
json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded
```

from a place that says nothing about where the bad byte came from. Found by
classifying a genuine 2.4 KB reply.

Fixed in two places:

- `ComOutlookGateway::utf8()` — at the boundary where the bytes enter, so nothing
  downstream has to think about it.
- `OllamaAIService::utf8()` — defence in depth, because Outlook is not the only
  source. A CSV saved from Excel and a paste from Word both already flow into
  prompts.

`PromptEncodingTest` pins it, including that clean UTF-8 passes through
untouched.

---

## 9. Not in this phase

No calendar access, no contact sync, no meeting scheduling, no background
polling, no reading anything outside the inbox, and no acting on a follow-up
task automatically.
