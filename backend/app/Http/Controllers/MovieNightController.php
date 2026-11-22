<?php

namespace App\Http\Controllers;

use App\Http\Requests\CancelMovieNightRequest;
use App\Http\Requests\ListMovieNightsRequest;
use App\Http\Requests\WriteMovieNightRequest;
use App\Http\Resources\MovieNightResource;
use App\Models\MovieNight;
use App\Services\MovieNightAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MovieNightController extends Controller
{
    private const RELATIONS = [
        'organizer:id,name',
        'winningMovie:id,title,source,tmdb_id,deactivated_at',
    ];

    public function index(ListMovieNightsRequest $request): JsonResponse
    {
        $query = MovieNight::query()
            ->visibleTo($request->user())
            ->with(self::RELATIONS)
            ->withCount('participants')
            ->orderByDesc('starts_at')
            ->orderByDesc('id');

        if ($request->validated('status') !== null) {
            $query->where('status', $request->validated('status'));
        }

        $nights = $query->paginate(
            perPage: (int) $request->validated('per_page', 20),
            page: (int) $request->validated('page', 1),
        )->withQueryString();

        return MovieNightResource::collection($nights)
            ->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(
        WriteMovieNightRequest $request,
        MovieNightAccessService $access,
    ): JsonResponse {
        $attributes = $request->validated();
        $status = $attributes['status'] ?? MovieNight::STATUS_DRAFT;
        unset($attributes['status']);

        $night = $access->create(
            $request->user(),
            $attributes,
            $status,
        );

        return $this->respond($night, 201);
    }

    public function show(
        Request $request,
        MovieNight $movieNight,
    ): JsonResponse {
        Gate::authorize('view', $movieNight);

        return $this->respond($movieNight);
    }

    public function update(
        WriteMovieNightRequest $request,
        MovieNight $movieNight,
        MovieNightAccessService $access,
    ): JsonResponse {
        $attributes = $request->validated();
        $status = $attributes['status'] ?? null;
        unset($attributes['status']);

        $night = $access->update(
            $request->user(),
            $movieNight,
            $attributes,
            $status,
        );

        return $this->respond($night);
    }

    public function cancel(
        CancelMovieNightRequest $request,
        MovieNight $movieNight,
        MovieNightAccessService $access,
    ): JsonResponse {
        $night = $access->cancel($request->user(), $movieNight);

        return $this->respond($night);
    }

    private function respond(
        MovieNight $night,
        int $status = 200,
    ): JsonResponse {
        $night->load(self::RELATIONS)->loadCount('participants');

        return (new MovieNightResource($night))
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'private, no-store');
    }
}