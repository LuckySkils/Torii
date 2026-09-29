<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Enums\NotificationStatus;
use App\Models\FeedPoll;
use App\Models\NotificationLog;
use App\Services\QBittorrent\QbitHealth;

/**
 * The dashboard's health as a flat list of checks, `ok` or not, so the UI can
 * show only the problems. Every `detail` comes from what the checks already
 * produce: an exception message, the setting or config value involved, a stored
 * error. `ok: true` entries are included too; the frontend decides to hide them.
 *
 * `skipped: true` marks a check that wasn't run because an earlier one failed
 * (qBittorrent unreachable, or the login failed), so the UI can fold it into that
 * one problem instead of showing a badge per dependent check.
 */
final class HealthChecks
{
    /**
     * @param  array{total: int, completed: int, tasks: array<int, array{key: string, label: string, state: string, error: string|null}>}|null  $bootstrap
     * @return array<int, array{key: string, ok: bool, label: string, detail: string|null, skipped: bool}>
     */
    public function build(QbitHealth $qbit, ?FeedPoll $lastPoll, ?array $bootstrap): array
    {
        $checks = [
            ...$this->qbit($qbit),
            $this->notifications(),
            $this->bootstrap($bootstrap),
            $this->feedPolling($lastPoll),
        ];

        return array_map(fn (array $check) => [...$check, 'skipped' => $check['skipped'] ?? false], $checks);
    }

    /**
     * @return array<int, array{key: string, ok: bool, label: string, detail: string|null}>
     */
    private function qbit(QbitHealth $qbit): array
    {
        $checks = [
            'reachable' => ['qBittorrent reachable', $qbit->reachable],
            'auth' => ['qBittorrent login', $qbit->auth],
            'feed' => ['Feed in qBittorrent', $qbit->feed],
            'prefs' => ['qBittorrent RSS settings', $qbit->prefs],
            'category' => ['Download category', $qbit->category],
        ];

        $entries = [];

        foreach ($checks as $key => [$label, $ok]) {
            $detail = $qbit->details[$key] ?? null;

            $skipped = false;

            // One outage, one real problem: the checks that depend on the first
            // one say they weren't run rather than claiming separate failures.
            if (! $ok && $detail === null) {
                $detail = match (true) {
                    ! $qbit->reachable && $key !== 'reachable' => "Not checked: qBittorrent isn't reachable.",
                    ! $qbit->auth && $key !== 'auth' => "Not checked: couldn't log in to qBittorrent.",
                    default => null,
                };
                $skipped = $detail !== null;
            }

            $entries[] = ['key' => "qbit.{$key}", 'ok' => $ok, 'label' => $label, 'detail' => $ok ? null : $detail, 'skipped' => $skipped];
        }

        return $entries;
    }

    /**
     * Switched off is fine, not a problem. When on, the last attempt's outcome.
     *
     * @return array{key: string, ok: bool, label: string, detail: string|null}
     */
    private function notifications(): array
    {
        if (! config('subtracker.notifications.enabled')) {
            return ['key' => 'notifications', 'ok' => true, 'label' => 'Notifications', 'detail' => 'Disabled: NTFY_URL is not set.'];
        }

        $last = NotificationLog::query()->latest('sent_at')->latest('id')->first(['status', 'error']);
        $failed = $last?->status === NotificationStatus::Error;

        return [
            'key' => 'notifications',
            'ok' => ! $failed,
            'label' => 'Notifications',
            'detail' => $failed ? "Last notification failed: {$last->error}" : null,
        ];
    }

    /**
     * Pending or running tasks aren't a problem; a failed one is.
     *
     * @param  array{total: int, completed: int, tasks: array<int, array{key: string, label: string, state: string, error: string|null}>}|null  $bootstrap
     * @return array{key: string, ok: bool, label: string, detail: string|null}
     */
    private function bootstrap(?array $bootstrap): array
    {
        $failed = array_values(array_filter($bootstrap['tasks'] ?? [], fn (array $task) => $task['state'] === 'failed'));

        if ($failed !== []) {
            $detail = implode('; ', array_map(fn (array $task) => "{$task['label']}: {$task['error']}", $failed));

            return ['key' => 'bootstrap', 'ok' => false, 'label' => 'Initial data sync', 'detail' => $detail];
        }

        return [
            'key' => 'bootstrap',
            'ok' => true,
            'label' => 'Initial data sync',
            'detail' => $bootstrap === null ? null : "In progress: {$bootstrap['completed']} of {$bootstrap['total']} tasks done.",
        ];
    }

    /**
     * @return array{key: string, ok: bool, label: string, detail: string|null}
     */
    private function feedPolling(?FeedPoll $lastPoll): array
    {
        if ($lastPoll === null) {
            return ['key' => 'feed.polling', 'ok' => true, 'label' => 'Feed polling', 'detail' => 'No poll has run yet.'];
        }

        return [
            'key' => 'feed.polling',
            'ok' => $lastPoll->error === null,
            'label' => 'Feed polling',
            'detail' => $lastPoll->error === null ? null : 'Last poll failed'.($lastPoll->http_status ? " (HTTP {$lastPoll->http_status})" : '').": {$lastPoll->error}",
        ];
    }
}
