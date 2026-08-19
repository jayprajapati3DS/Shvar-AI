<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyResearchController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailDraftController;
use App\Http\Controllers\EmailGenerationController;
use App\Http\Controllers\EmailReplyController;
use App\Http\Controllers\FollowUpTaskController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LeadAnalysisController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadProductMatchController;
use App\Http\Controllers\PlaceholderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\Settings\AiLogController;
use App\Http\Controllers\Settings\AiPlaygroundController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\EmailSettingsController;
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

// ---- Phase 4: AI personalized sales email ---------------------------------
//
// NOTHING here sends an email to anyone. Generation produces drafts only;
// approval is a separate, explicit human action; and the only bound
// EmailServiceInterface is LocalTestEmailService, which writes to the local log.
//
// Generation is additive in exactly the same way as Phase 3 analysis: a new run
// inserts a new email_generations row and a fresh set of drafts, so regenerating
// builds history rather than overwriting what you already reviewed.

Route::post('leads/{lead}/emails', [EmailGenerationController::class, 'store'])
    ->name('leads.emails.generate');
Route::post('leads/{lead}/emails/{generation}/resolve/{choice}', [EmailGenerationController::class, 'resolve'])
    ->whereIn('choice', ['previous', 'new'])
    ->name('leads.emails.resolve');

Route::get('email-drafts', [EmailDraftController::class, 'index'])->name('email-drafts.index');
Route::get('email-drafts/{draft}', [EmailDraftController::class, 'show'])->name('email-drafts.show');
Route::put('email-drafts/{draft}', [EmailDraftController::class, 'update'])->name('email-drafts.update');
Route::delete('email-drafts/{draft}', [EmailDraftController::class, 'destroy'])->name('email-drafts.destroy');

// The approval gate. `approve` is the only route that can make a draft
// sendable, and `send` refuses anything that has not been through it.
Route::post('email-drafts/{draft}/approve', [EmailDraftController::class, 'approve'])
    ->name('email-drafts.approve');
Route::post('email-drafts/{draft}/reject', [EmailDraftController::class, 'reject'])
    ->name('email-drafts.reject');
Route::post('email-drafts/{draft}/archive', [EmailDraftController::class, 'archive'])
    ->name('email-drafts.archive');
Route::post('email-drafts/{draft}/send', [EmailDraftController::class, 'send'])
    ->name('email-drafts.send');

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

    // Sender profile, signature and writing preferences (Phase 4).
    Route::get('email', [EmailSettingsController::class, 'index'])->name('email.index');
    Route::put('email', [EmailSettingsController::class, 'update'])->name('email.update');
    Route::delete('email/signature', [EmailSettingsController::class, 'resetSignature'])
        ->name('email.signature.reset');

    // SMTP connection. Separate from the profile because it writes a credential:
    // the password is encrypted at rest and never sent back to the browser, so
    // the form posts a sentinel when it was not retyped.
    Route::put('email/smtp', [EmailSettingsController::class, 'updateSmtp'])->name('email.smtp.update');
    Route::post('email/smtp/test', [EmailSettingsController::class, 'testSmtp'])->name('email.smtp.test');
    Route::delete('email/smtp', [EmailSettingsController::class, 'forgetSmtp'])->name('email.smtp.forget');

    // Local request log
    Route::get('ai/logs', [AiLogController::class, 'index'])->name('ai.logs');
    Route::get('ai/logs/{aiRequest}', [AiLogController::class, 'show'])->name('ai.logs.show');
    Route::delete('ai/logs/{aiRequest}', [AiLogController::class, 'destroy'])->name('ai.logs.destroy');
    Route::delete('ai/logs', [AiLogController::class, 'clear'])->name('ai.logs.clear');
});

// ---- Replies and follow-ups ----------------------------------------------
//
// Reading the Outlook inbox is a DELIBERATE action, not a background poll -
// hence a POST you press rather than a schedule that runs. Only messages whose
// sender matches a Contact record are ever opened; OutlookMailboxSync owns that
// scope and nothing else in the mailbox is touched.

Route::get('replies', [EmailReplyController::class, 'index'])->name('replies.index');
Route::post('replies/sync', [EmailReplyController::class, 'store'])->name('replies.sync');
Route::post('replies/{reply}/classify', [EmailReplyController::class, 'classify'])
    ->name('replies.classify');
Route::post('replies/{reply}/review', [EmailReplyController::class, 'review'])
    ->name('replies.review');
Route::delete('replies/{reply}', [EmailReplyController::class, 'destroy'])->name('replies.destroy');

// Follow-ups. A task is a reminder with a date - nothing here sends, schedules
// or generates anything, and an overdue one does not chase anybody.
Route::get('follow-ups', [FollowUpTaskController::class, 'index'])->name('follow-ups.index');
Route::post('follow-ups', [FollowUpTaskController::class, 'store'])->name('follow-ups.store');
Route::put('follow-ups/{task}', [FollowUpTaskController::class, 'update'])->name('follow-ups.update');
Route::delete('follow-ups/{task}', [FollowUpTaskController::class, 'destroy'])->name('follow-ups.destroy');
Route::post('follow-ups/{task}/complete', [FollowUpTaskController::class, 'complete'])
    ->name('follow-ups.complete');
Route::post('follow-ups/{task}/dismiss', [FollowUpTaskController::class, 'dismiss'])
    ->name('follow-ups.dismiss');
Route::post('follow-ups/{task}/reopen', [FollowUpTaskController::class, 'reopen'])
    ->name('follow-ups.reopen');

// ---- Remaining placeholder -----------------------------------------------
//
// Email Drafts graduated in Phase 4 and Follow-ups just now, so one stub remains.
Route::get('knowledge-base', [PlaceholderController::class, 'knowledgeBase'])->name('knowledge-base.index');
