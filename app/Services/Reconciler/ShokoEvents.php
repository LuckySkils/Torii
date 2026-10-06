<?php

declare(strict_types=1);

namespace App\Services\Reconciler;

use App\Enums\ReconcilerEventType;

/**
 * Which Shoko SignalR invocations the reconciler keeps (§17), under either of
 * Shoko's naming schemes, and reading their fields under either set of names.
 * Everything else is dropped at the listener.
 *
 * Current (older) names were captured live; the newer ones come from Shokofin's
 * source, and their payloads are read with the same field names (unverified).
 */
final class ShokoEvents
{
    /**
     * @param  array<int, mixed>  $arguments
     * @return array{type: ReconcilerEventType, payload: array<string, mixed>}|null
     */
    public static function normalize(string $target, array $arguments): ?array
    {
        $payload = is_array($arguments[0] ?? null) ? $arguments[0] : null;

        if ($payload === null) {
            return null;
        }

        $type = match (true) {
            $target === 'ShokoEvent:FileMatched', $target === 'release:saved' => ReconcilerEventType::FileMatched,
            $target === 'ShokoEvent:SeriesUpdated' && ($payload['Reason'] ?? null) === 'Added' => ReconcilerEventType::SeriesAdded,
            $target === 'metadata:series.added' => ReconcilerEventType::SeriesAdded,
            default => null,
        };

        return $type === null ? null : ['type' => $type, 'payload' => $payload];
    }

    /** The file's path as Shoko reports it, reduced to the file name. */
    public static function filename(array $payload): ?string
    {
        $path = $payload['RelativePath'] ?? null;

        return is_string($path) && $path !== '' ? basename(str_replace('\\', '/', $path)) : null;
    }

    /**
     * `CrossReferences` (current) or `CrossRefs` (older servers).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function crossReferences(array $payload): array
    {
        $refs = $payload['CrossReferences'] ?? $payload['CrossRefs'] ?? [];

        return array_values(array_filter(is_array($refs) ? $refs : [], 'is_array'));
    }

    /** `ImportFolderID` (current) or `ManagedFolderID` (newer). */
    public static function folderId(array $payload): ?int
    {
        $id = $payload['ImportFolderID'] ?? $payload['ManagedFolderID'] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    public static function fileId(array $payload): ?int
    {
        return is_numeric($payload['FileID'] ?? null) ? (int) $payload['FileID'] : null;
    }

    /** The first non-null value of a cross-reference field, e.g. `AnidbAnimeID` or `SeriesID`. */
    public static function firstReference(array $payload, string $field): ?int
    {
        foreach (self::crossReferences($payload) as $ref) {
            if (is_numeric($ref[$field] ?? null)) {
                return (int) $ref[$field];
            }
        }

        return null;
    }
}
