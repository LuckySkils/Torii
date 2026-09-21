<?php

declare(strict_types=1);

namespace App\Services\SubsPlease;

final class ShowImageMatcher
{
    /**
     * @param  array<int, SearchResultItem>  $items
     */
    public function match(array $items, string $showName): ?SearchResultItem
    {
        return $this->firstWithImage($this->candidates($items, $showName));
    }

    /**
     * The result items for `$showName`: an exact `show` match if any exist,
     * otherwise a case-insensitive, trimmed fallback. Used both for picking an
     * image (further filtered to a non-empty `imageUrl`) and for premiere-date
     * lookups, which don't care whether an image is present.
     *
     * @param  array<int, SearchResultItem>  $items
     * @return array<int, SearchResultItem>
     */
    public function candidates(array $items, string $showName): array
    {
        $exact = array_values(array_filter(
            $items,
            fn (SearchResultItem $item) => $item->show === $showName,
        ));

        return $exact !== [] ? $exact : array_values(array_filter(
            $items,
            fn (SearchResultItem $item) => $this->normalize($item->show) === $this->normalize($showName),
        ));
    }

    /**
     * @param  array<int, SearchResultItem>  $candidates
     */
    public function firstWithImage(array $candidates): ?SearchResultItem
    {
        foreach ($candidates as $candidate) {
            if ($candidate->imageUrl !== null && $candidate->imageUrl !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
