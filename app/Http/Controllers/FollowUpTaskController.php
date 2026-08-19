<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FollowUpStatus;
use App\Enums\Priority;
use App\Http\Requests\StoreFollowUpTaskRequest;
use App\Models\FollowUpTask;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Follow-ups module.
 *
 * A task is a reminder with a date. Nothing here sends, schedules or generates
 * anything - completing a task does not trigger an email, and an overdue one
 * does not chase anybody. That is deliberate: the moment a to-do list can act
 * on its own, "human approval is mandatory" stops being true.
 */
class FollowUpTaskController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'source', 'due']);

        // Default view is what is actually outstanding. A list that opens on
        // "everything ever" is one nobody reads.
        if (($filters['status'] ?? null) === null && ($filters['due'] ?? null) === null) {
            $filters['status'] = FollowUpStatus::Open->value;
        }

        $tasks = FollowUpTask::query()
            ->with([
                'lead:id,company_id,contact_id,lead_status',
                'lead.company:id,name',
                'contact:id,first_name,last_name,email',
                'reply:id,subject,classification,received_at',
            ])
            ->filter($filters)
            ->soonestFirst()
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('FollowUps/Index', [
            'tasks' => $tasks->through(fn (FollowUpTask $t) => $this->present($t)),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => FollowUpStatus::options(),
                'priorities' => Priority::options(),
                'sources' => [
                    ['value' => FollowUpTask::SOURCE_MANUAL, 'label' => 'Written by me'],
                    ['value' => FollowUpTask::SOURCE_AI, 'label' => 'Suggested by AI'],
                ],
            ],
            'counts' => [
                'open' => FollowUpTask::open()->count(),
                'overdue' => FollowUpTask::open()
                    ->whereNotNull('due_on')
                    ->whereDate('due_on', '<', now())
                    ->count(),
                'today' => FollowUpTask::open()->whereDate('due_on', now())->count(),
                'suggested' => FollowUpTask::open()
                    ->where('source', FollowUpTask::SOURCE_AI)
                    ->count(),
            ],
            'leads' => Lead::query()
                ->with('company:id,name')
                ->latest('updated_at')
                ->limit(100)
                ->get()
                ->map(fn (Lead $l) => [
                    'value' => $l->id,
                    'label' => $l->company?->name ?? "Lead #{$l->id}",
                ])
                ->all(),
        ]);
    }

    public function store(StoreFollowUpTaskRequest $request): RedirectResponse
    {
        $lead = Lead::findOrFail($request->integer('lead_id'));

        FollowUpTask::create([
            ...$request->validated(),
            'contact_id' => $request->input('contact_id', $lead->contact_id),
            'status' => FollowUpStatus::Open,

            // Written by hand, and recorded as such. Knowing which items came
            // from a machine is the difference between a to-do list you trust
            // and one you stop reading.
            'source' => FollowUpTask::SOURCE_MANUAL,
        ]);

        return back()->with('success', 'Follow-up added.');
    }

    public function update(StoreFollowUpTaskRequest $request, FollowUpTask $task): RedirectResponse
    {
        $task->update($request->validated());

        return back()->with('success', 'Follow-up updated.');
    }

    public function complete(FollowUpTask $task): RedirectResponse
    {
        $task->update([
            'status' => FollowUpStatus::Done,
            'completed_at' => now(),
        ]);

        return back()->with('success', 'Marked done.');
    }

    /**
     * Dismiss a task.
     *
     * Separate from done on purpose: "I did this" and "this was never worth
     * doing" are different facts, and collapsing them would make the AI's
     * suggestions look more useful than they were.
     */
    public function dismiss(FollowUpTask $task): RedirectResponse
    {
        $task->update([
            'status' => FollowUpStatus::Dismissed,
            'completed_at' => null,
        ]);

        return back()->with('success', 'Dismissed.');
    }

    public function reopen(FollowUpTask $task): RedirectResponse
    {
        $task->update([
            'status' => FollowUpStatus::Open,
            'completed_at' => null,
        ]);

        return back()->with('success', 'Reopened.');
    }

    public function destroy(FollowUpTask $task): RedirectResponse
    {
        $task->delete();

        return back()->with('success', 'Follow-up deleted.');
    }

    /** @return array<string, mixed> */
    private function present(FollowUpTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'notes' => $task->notes,

            'status' => $task->status->value,
            'status_color' => $task->status->color(),
            'priority' => $task->priority?->value,

            'source' => $task->source,
            'from_ai' => $task->isFromAi(),

            'due_on' => $task->due_on?->toDateString(),
            'overdue' => $task->isOverdue(),
            'completed_at' => $task->completed_at?->toIso8601String(),

            'lead_id' => $task->lead_id,
            'company' => $task->lead?->company?->name,
            'contact' => $task->contact?->full_name,

            'reply' => $task->reply === null ? null : [
                'id' => $task->reply->id,
                'subject' => $task->reply->subject,
                'classification' => $task->reply->classification?->value,
                'received_at' => $task->reply->received_at?->toIso8601String(),
            ],
        ];
    }
}
