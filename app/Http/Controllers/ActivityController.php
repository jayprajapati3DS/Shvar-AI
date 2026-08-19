<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Http\Controllers\Concerns\RedirectsToOrigin;
use App\Http\Requests\StoreActivityRequest;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;

/**
 * Timeline entries for leads and companies.
 *
 * Phase 1 supports hand-logged Note / Call / Meeting entries. Phase 2 adds
 * system-written Email and Follow-up entries through the same table.
 */
class ActivityController extends Controller
{
    use RedirectsToOrigin;

    /** @var array<string, class-string<Model>> */
    private const SUBJECTS = [
        'leads' => Lead::class,
        'companies' => Company::class,
    ];

    public function store(StoreActivityRequest $request, string $subjectType, int $subjectId): RedirectResponse
    {
        $subject = $this->resolveSubject($subjectType, $subjectId);

        $subject->activities()->create([
            'type' => ActivityType::from($request->validated('type')),
            'title' => $request->validated('title'),
            'body' => $request->validated('body'),
            'occurred_at' => $request->validated('occurred_at') ?? now(),
        ]);

        return $this->backTo($subjectType.'.index')->with('success', 'Activity logged.');
    }

    public function destroy(string $subjectType, int $subjectId, Activity $activity): RedirectResponse
    {
        $subject = $this->resolveSubject($subjectType, $subjectId);

        abort_unless(
            $activity->subject_type === $subject::class && $activity->subject_id === $subject->id,
            404
        );

        $activity->delete();

        return $this->backTo($subjectType.'.index')->with('success', 'Activity removed.');
    }

    private function resolveSubject(string $subjectType, int $subjectId): Model
    {
        $class = self::SUBJECTS[$subjectType] ?? abort(404);

        return $class::findOrFail($subjectId);
    }
}
