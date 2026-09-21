<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReleaseResource;
use App\Http\Resources\ShowResource;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $tracked = (string) $request->query('tracked', 'all');
        $sort = (string) $request->query('sort', 'name');

        $query = Show::query()->with('latestRelease');

        if ($search !== '') {
            $query->whereLike('name', '%'.$this->escapeLikeValue($search).'%');
        }

        if ($tracked === 'yes') {
            $query->where('is_tracked', true);
        } elseif ($tracked === 'no') {
            $query->where('is_tracked', false);
        }

        match ($sort) {
            'last_seen' => $query->orderByDesc('last_seen_at'),
            default => $query->orderBy('name'),
        };

        return Inertia::render('Shows/Index', [
            'shows' => ShowResource::collection($query->paginate(25)->withQueryString()),
            'filters' => [
                'q' => $search,
                'tracked' => $tracked,
                'sort' => $sort,
            ],
        ]);
    }

    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function show(Show $show): Response
    {
        return Inertia::render('Shows/Show', [
            'show' => (new ShowResource($show))->resolve(),
            'releases' => $show->releases()->latest('published_at')->get()
                ->map(fn (Release $release) => (new ReleaseResource($release))->resolve())
                ->all(),
        ]);
    }
}
