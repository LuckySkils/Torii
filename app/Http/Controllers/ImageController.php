<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ImageStatus;
use App\Jobs\FetchShowImage;
use App\Models\Show;
use App\Models\ShowImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ImageController extends Controller
{
    public function show(Request $request, Show $show): Response
    {
        $image = ShowImage::where('show_id', $show->id)->first();

        if ($image === null) {
            abort(404);
        }

        $etag = '"'.$image->sha256.'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=31536000, immutable');
        }

        return response(base64_decode($image->data), 200)
            ->header('Content-Type', $image->mime)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
    }

    public function refresh(Show $show): RedirectResponse
    {
        FetchShowImage::dispatch($show->id, force: true);

        return back()->with('success', "Refreshing the image for \"{$show->name}\".");
    }

    public function refreshMissing(): RedirectResponse
    {
        $shows = Show::whereIn('image_status', [ImageStatus::None, ImageStatus::Missing, ImageStatus::Error])->get();

        foreach ($shows as $i => $show) {
            FetchShowImage::dispatch($show->id)->delay(now()->addSeconds($i * 3));
        }

        return back()->with('success', "Fetching images for {$shows->count()} shows.");
    }
}
