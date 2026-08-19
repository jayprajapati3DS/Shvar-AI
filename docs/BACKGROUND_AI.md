# Background AI work

Everything the local model does now happens in a background worker instead of the browser request.

This document covers what moved, what deliberately did not, how to tell whether it is working, and
what the progress bar does and does not mean.

---

## 1. Why

An analysis takes four to five minutes on this machine. Writing an email takes one to two. Until
now that time was spent inside a single HTTP request, which meant a page you could look at and not
use — no navigating, no editing another lead, no checking a company while you waited. If the request
timed out, or the browser tab was closed, the work was simply lost.

The work still takes the same wall-clock time. What changed is whose time it is.

---

## 2. What moved

| Action | Typical | Job class |
| --- | --- | --- |
| Sales analysis of a lead | 4–5 min | `App\Jobs\Ai\RunLeadAnalysis` |
| Writing email drafts | 1–2 min | `App\Jobs\Ai\GenerateEmailDrafts` |
| Company website research | ~1 min | `App\Jobs\Ai\ResearchCompanyWebsite` |
| Reading a reply | ~30 sec | `App\Jobs\Ai\ClassifyEmailReply` |

All four are calls to the local model and nothing else.

### What did not move, and why

**Anything that drives Outlook.** Reading the mailbox and handing a draft to the compose window
talk to the desktop application over COM, which belongs to the interactive Windows session you are
sitting in front of. Those stay in the browser request, where you can watch them happen and where
the Outlook security prompts land somewhere you will see them.

**Approval.** Every rule about what the AI may produce and who signs it off is exactly where it
was. A finished job leaves a suggestion on a page for a human to accept or throw away. Nothing here
sends anything.

---

## 3. Running a worker

`composer dev` starts one alongside the web server and Vite — nothing extra to do.

Running the pieces separately needs two terminals:

```powershell
php artisan serve
php artisan queue:work --tries=1 --timeout=3600
```

`--tries=1` matters. The queue's instinct is to retry a failed job, which is right for sending an
email and wrong here: these cost minutes of CPU, and a model that produced unparseable JSON once
will most likely do it again. Three attempts is a quarter of an hour of fans spinning for the same
failure. If something should be retried, that is your call — the button is on the page.

`--timeout=3600` is generous on purpose. A slow CPU run should never be killed mid-sentence.

### If you would rather not run a worker

Set `QUEUE_CONNECTION=sync` in `.env`. Jobs then run inline in the browser request, exactly as they
did before — the page waits, but nothing else about the behaviour changes, and failures are still
reported properly rather than as a 500. The test suite runs this way.

---

## 4. When there is no worker

This is the one failure mode that dwarfs the rest, and it is silent: nothing errors, nothing logs,
the job simply sits in the table while a spinner turns.

So the worker leaves a note in the cache each time it looks for work, and the interface reads it.
When the activity tray sees **all three** of

- no note from a worker in the last minute, **and**
- something queued for more than 25 seconds, **and**
- nothing currently running,

it stops guessing and tells you, with the command to paste.

All three conditions are needed. A worker in the middle of a five-minute analysis is not polling, so
it leaves no note either — the job in flight is what distinguishes "busy" from "gone".

### Jobs abandoned mid-run

Closing the terminal during an analysis kills the process outright. No exception is thrown and no
`failed()` hook runs, so without a sweep the row would sit at Running for ever — and a spinner for
work that stopped days ago is worse than an error, because it implies something is still coming.

Anything still marked Running an hour after it started is closed out as failed the next time the
tray is read. Nothing was saved; re-run it.

---

## 5. The activity tray

Bottom right of every page. Collapsed to a single line while work is running, gone entirely when
there is none.

- **Running** — label, elapsed time, estimated progress.
- **Finished** — the summary that used to be a flash message, and a **View result** link to where
  the output landed.
- **Failed** — the same friendly error the page used to show.

Finished work stays until you dismiss it rather than fading on a timer. The whole point of running
in the background is that you were doing something else while it ran, and a result that disappears
after ten seconds is a result you will miss. Dismissing hides the row; it is kept in `ai_jobs` as
history, like the AI log.

A toast also fires when something finishes, so "go and do something else" is honest advice rather
than an instruction to keep checking the corner of the screen.

### Progress is an estimate

A local model returns one response at the end. There is no progress to report, and any bar claiming
otherwise is inventing it.

What the bar shows is elapsed time against a typical run for that kind of job, eased towards a
ceiling it never reaches while the work is still going. A bar sitting at 100% during a run that has
not finished would be a lie told with a straight face; one creeping past 90% reads as "longer than
usual", which is true. The tray labels it *Estimated — the model reports no progress*.

---

## 6. Two tables, not one

Laravel already has a `jobs` table. `ai_jobs` is not that.

`jobs` is the queue's own plumbing: a serialised closure that vanishes the moment it succeeds. It
holds nothing you could show a person — no name, no subject, no result, and no trace at all of the
job that finished a minute ago.

`ai_jobs` is the part you can see. One row per thing you asked for, written **before** the job is
queued and outliving it. That ordering matters: if the worker wrote the row, then a queue with no
worker would show an empty tray, and "nothing queued" would look identical to "nothing coming".

---

## 7. Duplicate work

One job of each kind per record. Clicking Analyse twice on the same lead — which is what happens
when the first click appears to do nothing — queues one analysis, not two competing for the same
CPU. Analysing two different leads at once is fine, and so is writing an email for a lead while
analysing it.

The button on the page reads its state from the same job record, so it says *Analysing…* rather than
offering to start something that is already going. That state survives navigating away and back,
because it comes from the server rather than a flag in the page.

---

## 8. Cancelling

While a job is **queued**, cancelling removes it. The queued job is left where it is rather than
hunted down in the jobs table; it checks the row when it wakes and returns having done nothing.

Once the model is **generating**, there is nothing to cancel that would not waste the minutes
already spent — the request is in flight and will finish whether or not anybody is watching. The
tray says so rather than offering a button that pretends. Dismiss the result instead.

---

## 9. What a job re-checks when it runs

Time passes between asking for something and it happening. A recommendation can be rejected, a
product deactivated, an email address cleared, or the whole record deleted while a job waits its
turn.

So every check the controller made before queueing is made again inside the job. In particular:
**an email is written only from a product direction you approved**, and that has to hold at the
moment of writing, not at the moment of asking. A job whose recommendation was withdrawn writes
nothing and says why.

---

## 10. Privacy

Nothing changed. The worker is a second process on the same machine, talking to the same local
Ollama over `127.0.0.1`, writing to the same SQLite file. No job reaches the network except company
research, which fetches the one address stored on the company record — the same single fetch it
always made, subject to the same `RESEARCH_FETCH_ENABLED` switch.

See [LOCAL_AI.md](LOCAL_AI.md) for the full data-flow.
