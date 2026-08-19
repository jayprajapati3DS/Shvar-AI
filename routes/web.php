<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyResearchController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LeadAnalysisController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadProductMatchController;
use App\Http\Controllers\PlaceholderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Settings\AiLogController;
use App\Http\Controllers\Settings\AiPlaygroundController;
use App\Http\Controllers\Settings\AiSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Shvar AI Copilot routes
|--------------------------------------------------------------------------
|
| Every route is local-only and rendered through Inertia. There is no auth
| layer in Phase 1: this is a single-user application bound to localhost.
|
| Create/edit forms are modals on the index and detail pages, so the resource
| routes deliberately omit `create` and `edit`.
|
*/

Route::get('/', DashboardController::class)->name('dashboard');

// ---- Fully implemented modules -------------------------------------------

Route::resource('companies', CompanyController::class)->except(['create', 'edit']);
Route::resource('contacts', ContactController::class)->except(['create', 'edit']);
Route::resource('leads', LeadController::class)->except(['create', 'edit']);
Route::resource('products', ProductController::class)->except(['create', 'edit']);

// Bulk actions on the list pages: edit the fields many records can share, or
// delete a selection.
//
// Declared BEFORE nothing in particular - they sit under their own path segment
// so they can never be mistaken for a record id by the resource routes above.
//
// Both are POST, including the delete. The selection is a list of ids that does
// not fit comfortably in a query string, and every browser handles a POST body
// consistently where DELETE-with-body is patchy.
foreach ([
    'companies' => CompanyController::class,
    'contacts' => ContactController::class,
    'leads' => LeadController::class,
    'products' => ProductController::class,
] as $resource => $controller) {
    Route::post("{$resource}/bulk/update", [$controller, 'bulkUpdate'])
        ->name("{$resource}.bulk-update");
    Route::post("{$resource}/bulk/delete", [$controller, 'bulkDestroy'])
        ->name("{$resource}.bulk-destroy");
}

// Manual product <-> lead matching (Phase 2 adds an AI-driven equivalent).
Route::post('leads/{lead}/products', [LeadProductMatchController::class, 'store'])
    ->name('leads.products.store');
Route::delete('leads/{lead}/products/{match}', [LeadProductMatchController::class, 'destroy'])
    ->name('leads.products.destroy');

// ---- Company website research --------------------------------------------
//
// The ONE feature that makes an outbound request: it fetches the company
// website you typed so the model reads real text instead of recalling from
// memory. PublicUrlGuard blocks private and loopback addresses at every
// redirect hop. Findings never touch the company record until `apply`.

Route::post('companies/{company}/research', [CompanyResearchController::class, 'store'])
    ->name('companies.research');
Route::get('companies/{company}/research/{analysis}', [CompanyResearchController::class, 'show'])
    ->name('companies.research.show');
Route::post('companies/{company}/research/{analysis}/apply', [CompanyResearchController::class, 'apply'])
    ->name('companies.research.apply');
Route::delete('companies/{company}/research/{analysis}', [CompanyResearchController::class, 'destroy'])
    ->name('companies.research.destroy');

// ---- Phase 3: AI Sales Intelligence --------------------------------------
//
// Analysis is additive: every run creates a new lead_analyses row, so history is
// never overwritten. Accept/reject are the ONLY ways a recommendation leaves the
// Suggested state - nothing here changes a lead's status, priority or stage.

Route::post('leads/{lead}/analyze', [LeadAnalysisController::class, 'store'])
    ->name('leads.analyze');
Route::get('leads/{lead}/analyses/{analysis}', [LeadAnalysisController::class, 'show'])
    ->name('leads.analyses.show');
Route::post('leads/{lead}/recommendations/{match}/accept', [LeadAnalysisController::class, 'accept'])
    ->name('leads.recommendations.accept');
Route::post('leads/{lead}/recommendations/{match}/reject', [LeadAnalysisController::class, 'reject'])
    ->name('leads.recommendations.reject');
Route::post('leads/{lead}/recommendations/{match}/archive', [LeadAnalysisController::class, 'archive'])
    ->name('leads.recommendations.archive');

// Activity timeline, shared by leads / companies / contacts.
Route::post('{subjectType}/{subjectId}/activities', [ActivityController::class, 'store'])
    ->whereIn('subjectType', ['leads', 'companies', 'contacts'])
    ->whereNumber('subjectId')
    ->name('activities.store');
Route::delete('{subjectType}/{subjectId}/activities/{activity}', [ActivityController::class, 'destroy'])
    ->whereIn('subjectType', ['leads', 'companies', 'contacts'])
    ->whereNumber('subjectId')
    ->name('activities.destroy');

// ---- CSV import -----------------------------------------------------------

Route::get('import', [ImportController::class, 'create'])->name('import.create');
Route::get('import/template', [ImportController::class, 'template'])->name('import.template');
Route::post('import/preview', [ImportController::class, 'preview'])->name('import.preview');
Route::post('import', [ImportController::class, 'store'])->name('import.store');

// ---- Settings (Phase 2: local AI) ----------------------------------------
//
// Every AI route goes through App\Services\AI\AIServiceInterface. Note there is
// no route that accepts an endpoint URL: OLLAMA_URL is server configuration only,
// so the browser cannot point AI traffic at a remote host.

Route::redirect('settings', '/settings/ai')->name('settings.index');

Route::prefix('settings')->name('settings.')->group(function (): void {
    // AI configuration + live connection/model status
    Route::get('ai', [AiSettingsController::class, 'index'])->name('ai.index');
    Route::put('ai', [AiSettingsController::class, 'update'])->name('ai.update');
    Route::post('ai/test', [AiSettingsController::class, 'test'])->name('ai.test');
    Route::post('ai/refresh', [AiSettingsController::class, 'refresh'])->name('ai.refresh');
    Route::delete('ai/system-prompt', [AiSettingsController::class, 'resetSystemPrompt'])
        ->name('ai.system-prompt.reset');

    // Developer/testing screen
    Route::get('ai/playground', [AiPlaygroundController::class, 'index'])->name('ai.playground');
    Route::post('ai/playground', [AiPlaygroundController::class, 'run'])->name('ai.playground.run');

    // Local request log
    Route::get('ai/logs', [AiLogController::class, 'index'])->name('ai.logs');
    Route::get('ai/logs/{aiRequest}', [AiLogController::class, 'show'])->name('ai.logs.show');
    Route::delete('ai/logs/{aiRequest}', [AiLogController::class, 'destroy'])->name('ai.logs.destroy');
    Route::delete('ai/logs', [AiLogController::class, 'clear'])->name('ai.logs.clear');
});

// ---- Phase 1 placeholders (still awaiting Phase 3) -----------------------

Route::get('email-drafts', [PlaceholderController::class, 'emailDrafts'])->name('email-drafts.index');
Route::get('follow-ups', [PlaceholderController::class, 'followUps'])->name('follow-ups.index');
Route::get('knowledge-base', [PlaceholderController::class, 'knowledgeBase'])->name('knowledge-base.index');
