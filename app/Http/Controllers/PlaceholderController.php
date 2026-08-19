<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The last stub.
 *
 * Settings graduated in Phase 2, Email Drafts in Phase 4, and Follow-ups when
 * Outlook replies arrived. Only the Knowledge Base is still waiting - it needs
 * document storage and local retrieval, which nothing else here depends on.
 */
class PlaceholderController extends Controller
{
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
