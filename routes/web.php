<?php

use App\Http\Controllers\AnimeController;
use App\Http\Controllers\AnimeLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NyaaImportController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ShowController;
use App\Http\Controllers\ShowTrackingController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::get('shows', [ShowController::class, 'index'])->name('shows.index');
Route::get('shows/{show}', [ShowController::class, 'show'])->name('shows.show');

Route::patch('shows/{show}/track', [ShowTrackingController::class, 'track'])->name('shows.track');
Route::post('shows/track-bulk', [ShowTrackingController::class, 'trackBulk'])->name('shows.track-bulk');
Route::delete('shows/{show}/rule', [ShowTrackingController::class, 'deleteRule'])->name('shows.delete-rule');
Route::get('shows/{show}/matches', [ShowTrackingController::class, 'matches'])->name('shows.matches');
Route::post('shows/{show}/queue-missing', [ShowTrackingController::class, 'queueMissing'])->name('shows.queue-missing');

Route::post('releases/{release}/download', [ReleaseController::class, 'download'])->name('releases.download');

// Delivery reconciler (§17): re-run one delivery's chain from the start.
Route::post('deliveries/{delivery}/repair', [DeliveryController::class, 'repair'])->name('deliveries.repair');

// Import from a Nyaa RSS link (§16). The preview fetches a user-supplied URL, hence the limit.
Route::post('import/nyaa/preview', [NyaaImportController::class, 'preview'])->middleware('throttle:nyaa-import')->name('import.nyaa.preview');
Route::post('import/nyaa/confirm', [NyaaImportController::class, 'confirm'])->name('import.nyaa.confirm');

Route::get('shows/{show}/image', [ImageController::class, 'show'])->name('shows.image');
Route::post('shows/{show}/image/refresh', [ImageController::class, 'refresh'])->name('shows.image.refresh');
Route::post('images/refresh-missing', [ImageController::class, 'refreshMissing'])->name('images.refresh-missing');

Route::post('feed/poll', [SystemController::class, 'pollFeed'])->name('feed.poll');
Route::post('qbit/reconcile', [SystemController::class, 'reconcile'])->name('qbit.reconcile');

Route::post('notifications/test', [NotificationController::class, 'test'])->name('notifications.test');

Route::get('shows/{show}/link/search', [AnimeLinkController::class, 'search'])->name('shows.link.search');
Route::get('shows/{show}/link/suggestions', [AnimeLinkController::class, 'suggestions'])->name('shows.link.suggestions');
Route::delete('shows/{show}/link/suggestions/{anime}', [AnimeLinkController::class, 'rejectSuggestion'])->name('shows.link.suggestions.reject');
Route::post('shows/{show}/link', [AnimeLinkController::class, 'store'])->name('shows.link.store');
Route::delete('shows/{show}/link', [AnimeLinkController::class, 'destroy'])->name('shows.link.destroy');

Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule');

Route::get('anime', [AnimeController::class, 'index'])->name('anime.index');
Route::get('anime/{anime}', [AnimeController::class, 'show'])->name('anime.show');
Route::get('anime/{id}/card', [AnimeController::class, 'card'])->whereNumber('id')->name('anime.card');
Route::get('anime/{anime}/cover', [AnimeController::class, 'cover'])->name('anime.cover');
