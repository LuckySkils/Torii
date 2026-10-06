<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Nyaa\NyaaException;
use App\Services\Nyaa\NyaaImporter;
use App\Services\Nyaa\NyaaImportPreviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Import from a Nyaa RSS link (§16): preview what it would add, then confirm the
 * chosen items. JSON endpoints for the import dialog.
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
}
