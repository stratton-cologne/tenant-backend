<?php

use App\Http\Controllers\Api\Tickets\TicketAttachmentsController;
use App\Http\Controllers\Api\Tickets\TicketCommentsController;
use App\Http\Controllers\Api\Tickets\TicketInboundEmailController;
use App\Http\Controllers\Api\Tickets\TicketReportsController;
use App\Http\Controllers\Api\Tickets\TicketSlaPolicyController;
use App\Http\Controllers\Api\Tickets\TicketsController;
use App\Http\Controllers\Api\Tickets\TicketTaxonomyController;
use App\Http\Controllers\Api\Tickets\TicketWatchersController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenant')->middleware(['auth.token', 'entitled:tickets'])->group(function (): void {
    Route::get('/tickets', [TicketsController::class, 'index'])->middleware('permission:tickets.read');
    Route::post('/tickets', [TicketsController::class, 'store'])->middleware('permission:tickets.create');
    Route::get('/tickets/{ticket}', [TicketsController::class, 'show'])->middleware('permission:tickets.read');
    Route::patch('/tickets/{ticket}', [TicketsController::class, 'update'])->middleware('permission:tickets.update');
    Route::delete('/tickets/{ticket}', [TicketsController::class, 'destroy'])->middleware('permission:tickets.delete');
    Route::post('/tickets/{ticket}/status', [TicketsController::class, 'updateStatus'])->middleware('permission:tickets.status.update');
    Route::post('/tickets/{ticket}/assign', [TicketsController::class, 'assign'])->middleware('permission:tickets.assign');

    Route::get('/tickets/{ticket}/comments', [TicketCommentsController::class, 'index'])->middleware('permission:tickets.read');
    Route::post('/tickets/{ticket}/comments', [TicketCommentsController::class, 'store'])->middleware('permission:tickets.comment.create');
    Route::delete('/tickets/{ticket}/comments/{comment}', [TicketCommentsController::class, 'destroy'])->middleware('permission:tickets.comment.delete');

    Route::get('/tickets/{ticket}/watchers', [TicketWatchersController::class, 'index'])->middleware('permission:tickets.read');
    Route::post('/tickets/{ticket}/watchers', [TicketWatchersController::class, 'store'])->middleware('permission:tickets.read');
    Route::delete('/tickets/{ticket}/watchers/{watcher}', [TicketWatchersController::class, 'destroy'])->middleware('permission:tickets.read');

    Route::post('/tickets/{ticket}/attachments', [TicketAttachmentsController::class, 'upload'])->middleware('permission:tickets.attachment.upload');
    Route::get('/tickets/{ticket}/attachments/{attachment}/versions/{version}/download', [TicketAttachmentsController::class, 'download'])->middleware('permission:tickets.attachment.read');

    Route::get('/ticket-types', [TicketTaxonomyController::class, 'indexTypes'])->middleware('permission:tickets.type.manage');
    Route::post('/ticket-types', [TicketTaxonomyController::class, 'storeType'])->middleware('permission:tickets.type.manage');
    Route::patch('/ticket-types/{item}', [TicketTaxonomyController::class, 'updateType'])->middleware('permission:tickets.type.manage');
    Route::delete('/ticket-types/{item}', [TicketTaxonomyController::class, 'destroyType'])->middleware('permission:tickets.type.manage');

    Route::get('/ticket-categories', [TicketTaxonomyController::class, 'indexCategories'])->middleware('permission:tickets.category.manage');
    Route::post('/ticket-categories', [TicketTaxonomyController::class, 'storeCategory'])->middleware('permission:tickets.category.manage');
    Route::patch('/ticket-categories/{item}', [TicketTaxonomyController::class, 'updateCategory'])->middleware('permission:tickets.category.manage');
    Route::delete('/ticket-categories/{item}', [TicketTaxonomyController::class, 'destroyCategory'])->middleware('permission:tickets.category.manage');

    Route::get('/ticket-tags', [TicketTaxonomyController::class, 'indexTags'])->middleware('permission:tickets.tag.manage');
    Route::post('/ticket-tags', [TicketTaxonomyController::class, 'storeTag'])->middleware('permission:tickets.tag.manage');
    Route::patch('/ticket-tags/{item}', [TicketTaxonomyController::class, 'updateTag'])->middleware('permission:tickets.tag.manage');
    Route::delete('/ticket-tags/{item}', [TicketTaxonomyController::class, 'destroyTag'])->middleware('permission:tickets.tag.manage');

    Route::get('/ticket-queues', [TicketTaxonomyController::class, 'indexQueues'])->middleware('permission:tickets.queue.manage');
    Route::post('/ticket-queues', [TicketTaxonomyController::class, 'storeQueue'])->middleware('permission:tickets.queue.manage');
    Route::patch('/ticket-queues/{item}', [TicketTaxonomyController::class, 'updateQueue'])->middleware('permission:tickets.queue.manage');
    Route::delete('/ticket-queues/{item}', [TicketTaxonomyController::class, 'destroyQueue'])->middleware('permission:tickets.queue.manage');

    Route::get('/tickets/sla-policies', [TicketSlaPolicyController::class, 'index'])->middleware('permission:tickets.sla.manage');
    Route::post('/tickets/sla-policies', [TicketSlaPolicyController::class, 'upsert'])->middleware('permission:tickets.sla.manage');

    Route::get('/tickets/reports/sla-breaches', [TicketReportsController::class, 'slaBreaches'])->middleware('permission:tickets.report.read');
    Route::post('/tickets/email/inbound', [TicketInboundEmailController::class, 'inbound'])->middleware('permission:tickets.comment.create');
});
