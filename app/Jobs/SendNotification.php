<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Notifier;
use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Models\Release;
use App\Models\Show;
use App\Services\Notifications\NotificationMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SendNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly NotificationKind $kind,
        public readonly ?int $releaseId = null,
    ) {}

    public function handle(Notifier $notifier): void
    {
        if ($this->alreadySent()) {
            return;
        }

        $message = $this->buildMessage();

        if ($message === null) {
            return;
        }

        $notifier->send($message);

        NotificationLog::create([
            'kind' => $this->kind,
            'release_id' => $this->releaseId,
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        NotificationLog::create([
            'kind' => $this->kind,
            'release_id' => $this->releaseId,
            'status' => NotificationStatus::Error,
            'error' => $exception->getMessage(),
            'sent_at' => now(),
        ]);
    }

    private function alreadySent(): bool
    {
        if ($this->releaseId === null) {
            return false;
        }

        return NotificationLog::where('kind', $this->kind)
            ->where('release_id', $this->releaseId)
            ->exists();
    }

    private function buildMessage(): ?NotificationMessage
    {
        $release = $this->releaseId !== null ? Release::find($this->releaseId) : null;

        if ($release === null || $release->show === null) {
            return null;
        }

        $show = $release->show;

        return match ($this->kind) {
            NotificationKind::NewEpisode => new NotificationMessage(
                title: $release->is_batch
                    ? "{$show->name} — batch {$release->batch_from}–{$release->batch_to} is out"
                    : "{$show->name} — episode {$release->episode} is out",
                message: $release->title,
                tags: ['tv'],
                clickUrl: $this->clickUrl($show),
            ),
            NotificationKind::Downloaded => new NotificationMessage(
                title: "{$show->name} — episode {$release->episode} downloaded",
                message: $release->title,
                tags: ['white_check_mark'],
                clickUrl: $this->clickUrl($show),
            ),
            // Sent synchronously by NotificationController::test; this job never
            // handles the "test" kind.
            NotificationKind::Test => null,
        };
    }

    private function clickUrl(Show $show): string
    {
        $base = rtrim((string) (config('subtracker.notifications.click_url') ?: config('app.url')), '/');

        return $base.route('shows.show', $show, absolute: false);
    }
}
