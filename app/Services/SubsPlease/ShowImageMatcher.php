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
        $exact = array_values(array_filter(
            $items,
            fn (SearchResultItem $item) => $item->show === $showName,
        ));

        $candidates = $exact !== [] ? $exact : array_values(array_filter(
            $items,
            fn (SearchResultItem $item) => $this->normalize($item->show) === $this->normalize($showName),
        ));

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
