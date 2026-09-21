<?php

declare(strict_types=1);

use App\Contracts\DownloadDriver;
use App\Events\NewReleaseDetected;
use App\Models\Release;
use Mockery\MockInterface;

test('dispatching NewReleaseDetected calls onNewRelease on the bound download driver', function () {
    $release = Release::create([
        'guid' => 'GUID-1',
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    $this->mock(DownloadDriver::class, function (MockInterface $mock) use ($release) {
        $mock->shouldReceive('onNewRelease')
            ->once()
            ->withArgs(fn (Release $arg) => $arg->is($release));
    });

    NewReleaseDetected::dispatch($release);
});
