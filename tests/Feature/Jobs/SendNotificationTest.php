<?php

declare(strict_types=1);

use App\Contracts\Notifier;
use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\NotificationLog;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\Http;

function notifiableShow(array $overrides = []): Show
{
    return Show::create(array_merge([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function notifiableRelease(Show $show, array $overrides = []): Release
{
    static $sequence = 0;
    $sequence++;

    return Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => 'GUID-N-'.$sequence,
        'title' => '[SubsPlease] Grand Blue S3 - 05 (1080p) [ABCD1234].mkv',
        'episode' => '05',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA'.$sequence,
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

function enableNtfyForTest(): void
{
    config([
        'subtracker.notifications.enabled' => true,
        'subtracker.notifications.ntfy_url' => 'http://ntfy.test',
        'subtracker.notifications.ntfy_topic' => 'torii',
        'subtracker.notifications.ntfy_token' => 'secret-token',
    ]);
}

test('sends a new-episode notification with the episode title and click URL', function () {
    enableNtfyForTest();
    $show = notifiableShow();
    $release = notifiableRelease($show);

    Http::preventStrayRequests();
    Http::fake(['http://ntfy.test' => Http::response('Ok.', 200)]);

    (new SendNotification(NotificationKind::NewEpisode, $release->id))->handle(app(Notifier::class));

    Http::assertSent(function ($request) use ($release) {
        return $request->url() === 'http://ntfy.test'
            && $request['topic'] === 'torii'
            && $request['title'] === 'Grand Blue S3 — episode 05 is out'
            && $request['message'] === $release->title
            && $request['tags'] === ['tv']
            && str_ends_with((string) $request['click'], "/shows/{$release->show_id}")
            && $request->hasHeader('Authorization', 'Bearer secret-token');
    });

    $log = NotificationLog::sole();
    expect($log->kind)->toBe(NotificationKind::NewEpisode)
        ->and($log->status)->toBe(NotificationStatus::Sent)
        ->and($log->release_id)->toBe($release->id);
});

test('sends a batch new-episode notification with a range in the title', function () {
    enableNtfyForTest();
    $show = notifiableShow();
    $release = notifiableRelease($show, [
        'episode' => null,
        'is_batch' => true,
        'batch_from' => 1,
        'batch_to' => 12,
        'title' => '[SubsPlease] Grand Blue S3 (01-12) (1080p) [Batch]',
    ]);

    Http::preventStrayRequests();
    Http::fake(['http://ntfy.test' => Http::response('Ok.', 200)]);

    (new SendNotification(NotificationKind::NewEpisode, $release->id))->handle(app(Notifier::class));

    Http::assertSent(fn ($request) => $request['title'] === 'Grand Blue S3 — batch 1–12 is out');
});

test('sends a downloaded notification', function () {
    enableNtfyForTest();
    $show = notifiableShow();
    $release = notifiableRelease($show);

    Http::preventStrayRequests();
    Http::fake(['http://ntfy.test' => Http::response('Ok.', 200)]);

    (new SendNotification(NotificationKind::Downloaded, $release->id))->handle(app(Notifier::class));

    Http::assertSent(function ($request) {
        return $request['title'] === 'Grand Blue S3 — episode 05 downloaded'
            && $request['tags'] === ['white_check_mark'];
    });

    expect(NotificationLog::sole()->kind)->toBe(NotificationKind::Downloaded);
});

test('does not send again once a notification for that kind and release is already logged', function () {
    enableNtfyForTest();
    $show = notifiableShow();
    $release = notifiableRelease($show);

    NotificationLog::create([
        'kind' => NotificationKind::NewEpisode,
        'release_id' => $release->id,
        'status' => NotificationStatus::Sent,
        'sent_at' => now(),
    ]);

    Http::preventStrayRequests();

    (new SendNotification(NotificationKind::NewEpisode, $release->id))->handle(app(Notifier::class));

    Http::assertNothingSent();
    expect(NotificationLog::count())->toBe(1);
});

test('failed() logs the final exception as an error', function () {
    $show = notifiableShow();
    $release = notifiableRelease($show);

    (new SendNotification(NotificationKind::NewEpisode, $release->id))->failed(new RuntimeException('ntfy is down'));

    $log = NotificationLog::sole();
    expect($log->status)->toBe(NotificationStatus::Error)
        ->and($log->error)->toBe('ntfy is down')
        ->and($log->release_id)->toBe($release->id);
});
