<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReleaseController;
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

Route::get('shows/{show}/image', [ImageController::class, 'show'])->name('shows.image');
Route::post('shows/{show}/image/refresh', [ImageController::class, 'refresh'])->name('shows.image.refresh');
Route::post('images/refresh-missing', [ImageController::class, 'refreshMissing'])->name('images.refresh-missing');

Route::post('feed/poll', [SystemController::class, 'pollFeed'])->name('feed.poll');
Route::post('qbit/reconcile', [SystemController::class, 'reconcile'])->name('qbit.reconcile');

Route::post('notifications/test', [NotificationController::class, 'test'])->name('notifications.test');
