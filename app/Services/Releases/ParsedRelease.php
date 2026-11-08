<?php

declare(strict_types=1);

namespace App\Services\Releases;

/**
 * A release title read by ReleaseTitleParser. `name` null means the title's shape
 * wasn't recognised (unparsed): nothing else is guessed then. `episode` keeps the
 * number as written ("06", "12.5"). `season` comes only from an explicit
 * `SxxEyy`/`Sxx` marker; SubsPlease titles keep the season inside the name, as
 * SubsPlease writes it ("Ao Ashi S2"), so theirs is always null.
 */
final readonly class ParsedRelease
{
    /**
     * @param  array<int, string>  $altTitles  other names the title gives for the show (ToonsHub's trailing parenthesis)
     */
    public function __construct(
        public ?string $group,
        public ?string $name,
        public ?int $season = null,
        public ?string $episode = null,
        public ?int $version = null,
        public bool $isBatch = false,
        public ?int $batchFrom = null,
        public ?int $batchTo = null,
        public ?string $resolution = null,
        public ?string $crc = null,
        public array $altTitles = [],
    ) {}

    public static function unparsed(?string $group = null): self
    {
        return new self(group: $group, name: null);
    }

    public function isParsed(): bool
    {
        return $this->name !== null;
    }

    /**
     * The show name Torii would suggest: the name, plus " S{season}" for a later
     * season (SubsPlease's own convention, e.g. "Ao Ashi S2"). Null when unparsed.
     */
    public function suggestedShowName(): ?string
    {
        if ($this->name === null) {
            return null;
        }

        return $this->season !== null && $this->season > 1 ? "{$this->name} S{$this->season}" : $this->name;
    }
}
