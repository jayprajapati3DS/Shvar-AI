<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\AiJobController;
use App\Services\AI\Jobs\AiJobQueue;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Props shared with every Inertia page.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'app' => [
                'name' => config('app.name'),
            ],

            // Consumed by the global toast container.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],

            // Background AI work, shared with every page so the tray is right
            // the moment a page renders. It is what makes navigating away from
            // a running analysis safe: whatever you open next already knows the
            // job is going, so the progress you left behind is still there.
            'ai_jobs' => fn () => AiJobController::payload(app(AiJobQueue::class)),
        ];
    }
}
