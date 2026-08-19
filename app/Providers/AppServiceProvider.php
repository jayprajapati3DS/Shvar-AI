<?php

namespace App\Providers;

use App\Services\AI\Jobs\AiJobDurations;
use App\Services\AI\Jobs\QueueWorkerStatus;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Shared for the length of a request so the median is measured once,
        // not once per job in the tray on every two-second poll.
        $this->app->singleton(AiJobDurations::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->recordQueueWorkerHeartbeat();
    }

    /**
     * Let the interface know a worker is alive.
     *
     * Without this there is no way to tell "the analysis is taking a while" from
     * "nothing is running and it never will", and those two look identical from
     * the browser: a job sitting in a table and a spinner turning. Only one of
     * them has a fix, and it is a command the user can paste.
     *
     * Both events matter. Looping fires when a worker checks for work and finds
     * none, which is the ordinary idle heartbeat. JobProcessing fires as one
     * starts, which keeps the note fresh through a run of quick jobs where the
     * worker barely idles at all.
     */
    private function recordQueueWorkerHeartbeat(): void
    {
        $beat = fn () => app(QueueWorkerStatus::class)->heartbeat();

        Event::listen(Looping::class, $beat);
        Event::listen(JobProcessing::class, $beat);
    }
}
