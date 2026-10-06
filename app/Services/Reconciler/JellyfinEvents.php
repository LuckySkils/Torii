<?php

declare(strict_types=1);

namespace App\Services\Reconciler;

use App\Enums\ReconcilerEventType;

/**
 * Which Jellyfin WebSocket messages the reconciler keeps (§17): `LibraryChanged`
 * with the Anime library among its `CollectionFolders`. The added/removed items
 * are not read: they carry unrelated churn (virtual seasons re-created on every
 * refresh), so checks go through the recently-added lookup instead.
 */
final class JellyfinEvents
{
    /**
     * @param  array<string, mixed>  $message  `{MessageType, Data}`
     * @return array{type: ReconcilerEventType, payload: array<string, mixed>}|null
     */
    public static function normalize(array $message, string $animeLibraryId): ?array
    {
        $data = $message['Data'] ?? null;

        if (($message['MessageType'] ?? null) !== 'LibraryChanged' || ! is_array($data)) {
            return null;
        }

        $library = self::id($animeLibraryId);
        $folders = array_map(fn ($id) => self::id((string) $id), is_array($data['CollectionFolders'] ?? null) ? $data['CollectionFolders'] : []);

        return $library !== '' && in_array($library, $folders, true)
            ? ['type' => ReconcilerEventType::LibraryChanged, 'payload' => $data]
            : null;
    }

    /** Jellyfin sends ids without dashes; config may have them. */
    public static function id(string $id): string
    {
        return strtolower(str_replace('-', '', trim($id)));
    }
}
