<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Nyaa\NyaaAnimeSearch;
use App\Services\Nyaa\NyaaException;
use App\Services\Nyaa\NyaaImporter;
use App\Services\Nyaa\NyaaImportPreviews;
use App\Services\Nyaa\SeriesTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Import from a Nyaa RSS link (§16): preview what it would add, point each
 * series at the show it belongs to, then confirm the chosen items. JSON
 * endpoints for the import dialog.
 */
class NyaaImportController extends Controller
{
    public function preview(Request $request, NyaaImportPreviews $previews): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']]);

        try {
            $preview = $previews->create($data['url']);
        } catch (NyaaException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['url' => [$e->getMessage()]]], 422);
        }

        return response()->json(NyaaImportPreviews::publicView($preview));
    }

    public function confirm(Request $request, NyaaImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'previewId' => ['required', 'string', 'max:100'],
            'keys' => ['required', 'array', 'min:1', 'max:500'],
            'keys.*' => ['required', 'string', 'max:100'],
        ]);

        return response()->json($importer->confirm($data['previewId'], $data['keys']));
    }

    /**
     * Points one series of a preview at another show: `target: { showId }`,
     * `target: { newName }`, or `target: { animeId, newName? }` (the anime's
     * linked show, or a new show linked to it). Returns the whole updated preview.
     */
    public function target(Request $request, string $previewId, NyaaImportPreviews $previews): JsonResponse
    {
        $choose = 'Choose a show or an anime, or give a name for a new show.';

        $data = $request->validate([
            'seriesKey' => ['required', 'string', 'max:300'],
            'target' => ['required', 'array'],
            'target.showId' => ['nullable', 'integer', 'required_without_all:target.newName,target.animeId', 'prohibits:target.newName,target.animeId'],
            'target.newName' => ['nullable', 'string', 'max:1000', 'required_without_all:target.showId,target.animeId'],
            'target.animeId' => ['nullable', 'integer', 'required_without_all:target.showId,target.newName'],
        ], [
            'target.showId.required_without_all' => $choose,
            'target.newName.required_without_all' => $choose,
            'target.animeId.required_without_all' => $choose,
            'target.showId.prohibits' => 'Choose either an existing show, or an anime or a new name, not both.',
        ]);

        $preview = $previews->retarget(
            $previewId,
            $data['seriesKey'],
            isset($data['target']['showId']) ? (int) $data['target']['showId'] : null,
            $data['target']['newName'] ?? null,
            isset($data['target']['animeId']) ? (int) $data['target']['animeId'] : null,
        );

        return response()->json(NyaaImportPreviews::publicView($preview));
    }

    /**
     * The target picker: `GET /import/nyaa/shows?q=&provider=1`. Shows by name,
     * and anime from the local catalog; `provider=1` also asks the metadata
     * provider once (limited per client; over the limit, or failing, it says so
     * in `providerError` and the local results still come back).
     */
    public function shows(Request $request, SeriesTargets $targets, NyaaAnimeSearch $anime): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'provider' => ['nullable', 'boolean'],
        ]);
        $q = (string) ($data['q'] ?? '');

        return response()->json([
            'shows' => $targets->search($q),
            ...$anime->search($q, (bool) ($data['provider'] ?? false), (string) $request->ip()),
        ]);
    }
}
