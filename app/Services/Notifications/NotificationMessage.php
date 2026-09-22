<?php

declare(strict_types=1);

namespace App\Services\Notifications;

final readonly class NotificationMessage
{
    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public string $title,
        public string $message,
        public array $tags = [],
        public ?int $priority = null,
        public ?string $clickUrl = null,
    ) {}
}
