<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use RuntimeException;

/**
 * A Nyaa import problem worth telling the user as is: a rejected link, a failed
 * fetch, an unreadable feed. Messages are plain sentences, safe to show.
 */
final class NyaaException extends RuntimeException {}
