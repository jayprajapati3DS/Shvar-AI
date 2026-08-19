<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\AiRequestStatus;
use App\Enums\AiRequestType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AiRequestResource;
use App\Models\AiRequest;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings -> AI Logs.
 *
 * Reads the local ai_requests table. Nothing here transmits anything: the log
 * exists so you can see exactly what was sent to the local model and what came
 * back, on your own machine.
 */
class AiLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'type', 'model']);

        $requests = AiRequest::query()
            ->filter($filters)
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Settings/AiLogs', [
            // Summary rows only - prompt/response are fetched per row on demand.
            'requests' => AiRequestResource::collection($requests),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => AiRequestStatus::options(),
                'types' => AiRequestType::options(),
                'models' => AiRequest::query()
                    ->distinct()
                    ->orderBy('model')
                    ->pluck('model')
                    ->all(),
            ],
        ]);
    }

    /**
     * Full prompt and response for one entry.
     *
     * A separate JSON endpoint rather than shipping every prompt in the list
     * payload - a page of 20 completions would otherwise be megabytes.
     */
    public function show(AiRequest $aiRequest): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'request' => (new AiRequestResource($aiRequest, withDetail: true))->resolve(),
        ]);
    }

    public function destroy(AiRequest $aiRequest): RedirectResponse
    {
        $aiRequest->delete();

        return back()->with('success', 'Log entry deleted.');
    }

    /** Empty the whole local log. */
    public function clear(): RedirectResponse
    {
        $deleted = AiRequest::count();

        AiRequest::query()->delete();

        return back()->with('success', "Cleared {$deleted} log entr(ies).");
    }
}
