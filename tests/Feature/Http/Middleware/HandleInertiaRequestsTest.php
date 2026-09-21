<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['subtracker.qbittorrent.url' => 'http://qbit.test:8080']);

    Http::preventStrayRequests();
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });
});

test('shares null flash values and the configured driver by default', function () {
    $this->get('/')->assertInertia(fn ($page) => $page->component('Dashboard')
        ->where('flash.success', null)
        ->where('flash.error', null)
        ->where('driver', 'rules'));
});

test('a flashed success message survives the next request as a shared prop', function () {
    session()->flash('success', 'It worked.');

    $this->get('/')->assertInertia(fn ($page) => $page->where('flash.success', 'It worked.')
        ->where('flash.error', null));
});

test('reflects the configured driver mode', function () {
    config(['subtracker.qbittorrent.mode' => 'push']);

    $this->get('/')->assertInertia(fn ($page) => $page->where('driver', 'push'));
});
