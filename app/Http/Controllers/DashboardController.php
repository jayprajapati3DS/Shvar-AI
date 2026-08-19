<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\LeadResource;
use App\Services\DashboardMetrics;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DashboardMetrics $metrics): Response
    {
        return Inertia::render('Dashboard', [
            'summary' => $metrics->summary(),
            'pipeline' => $metrics->pipeline(),
            'byPriority' => $metrics->byPriority(),
            'recentLeads' => LeadResource::collection($metrics->recentLeads()),
        ]);
    }
}
