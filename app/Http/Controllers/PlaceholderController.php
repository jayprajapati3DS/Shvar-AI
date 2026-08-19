<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 1 stubs for the modules that depend on the email/RAG layer.
 *
 * Settings graduated to a real module in Phase 2 (App\Http\Controllers\Settings),
 * so only three stubs remain here.
 *
 * Each renders a page describing what the module will do and what it is waiting
 * on, so the navigation is complete and nothing looks broken. None of them calls
 * the AI layer - that arrives with the Phase 3 features.
 */
class PlaceholderController extends Controller
{
    public function emailDrafts(): Response
    {
        return Inertia::render('Placeholders/EmailDrafts', [
            'context' => [
                // Real counts, so the page shows what the module will operate on.
                'leadsAwaitingOutreach' => Lead::whereIn('lead_status', ['Qualified', 'Approved'])->count(),
            ],
        ]);
    }

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
