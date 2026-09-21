<?php

declare(strict_types=1);

use App\Enums\ImageStatus;
use App\Jobs\FetchShowImage;
use App\Models\Show;
use Illuminate\Support\Facades\Queue;

function fetchableShow(string $name, ImageStatus $status): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'image_status' => $status,
    ]);
}

test('the default --missing selection covers none, missing and error, skipping found', function () {
    Queue::fake();

    fetchableShow('None Show', ImageStatus::None);
    fetchableShow('Missing Show', ImageStatus::Missing);
    fetchableShow('Error Show', ImageStatus::Error);
    fetchableShow('Found Show', ImageStatus::Found);

    $this->artisan('images:fetch')->assertSuccessful();

    Queue::assertPushed(FetchShowImage::class, 3);
});

test('--all re-checks every show, including ones already found', function () {
    Queue::fake();

    fetchableShow('None Show', ImageStatus::None);
    fetchableShow('Found Show', ImageStatus::Found);

    $this->artisan('images:fetch', ['--all' => true])->assertSuccessful();

    Queue::assertPushed(FetchShowImage::class, 2);
});

test('dispatches are spaced three seconds apart', function () {
    $this->freezeTime();
    Queue::fake();

    $first = fetchableShow('First Show', ImageStatus::None);
    $second = fetchableShow('Second Show', ImageStatus::None);

    $this->artisan('images:fetch')->assertSuccessful();

    Queue::assertPushed(FetchShowImage::class, fn ($job) => $job->showId === $first->id && $job->delay->equalTo(now()->addSeconds(0)));
    Queue::assertPushed(FetchShowImage::class, fn ($job) => $job->showId === $second->id && $job->delay->equalTo(now()->addSeconds(3)));
});
