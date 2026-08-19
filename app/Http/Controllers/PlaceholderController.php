<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stubs for the modules still waiting on a later phase.
 *
 * Settings graduated to a real module in Phase 2, and Email Drafts in Phase 4,
 * so two stubs remain here.
 *
 * Each renders a page describing what the module will do and what it is waiting
 * on, so the navigation is complete and nothing looks broken.
 */
class PlaceholderController extends Controller
{
    public function followUps(): Response
    {
        return Inertia::render('Placeholders/FollowUps', [
            'context' => [
                'leadsInFollowUp' => Lead::where('lead_status', 'Follow-up')->count(),
                'contactedLeads' => Lead::where('lead_status', 'Contacted')->count(),
            ],
        ]);
    }

    public function knowledgeBase(): Response
    {
        return Inertia::render('Placeholders/KnowledgeBase', [
            'context' => [
                'products' => Product::count(),
                'activeProducts' => Product::active()->count(),
            ],
        ]);
    }
}
