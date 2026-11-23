<?php

namespace App\Http\Controllers;

use App\Models\MovieNight;
use App\Services\MovieNightVotingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MovieNightVotingController extends Controller
{
    public function __construct(private MovieNightVotingService $voting) {}

    public function index(Request $request, MovieNight $movieNight): JsonResponse
    {
        return $this->respond($this->voting->summary($request->user(), $movieNight));
    }

    public function store(Request $request, MovieNight $movieNight): JsonResponse
    {
        Gate::authorize('vote', $movieNight);
        $movieId = $this->validateId($request, 'movie_id');

        return $this->respond($this->voting->propose($request->user(), $movieNight, $movieId), 201);
    }

    public function vote(Request $request, MovieNight $movieNight): JsonResponse
    {
        Gate::authorize('vote', $movieNight);
        $candidateId = $this->validateId($request, 'candidate_id');

        return $this->respond($this->voting->vote($request->user(), $movieNight, $candidateId));
    }

    public function finalize(Request $request, MovieNight $movieNight): JsonResponse
    {
        Gate::authorize('finalize', $movieNight);
        $candidateId = $this->validateId($request, 'candidate_id', required: false);

        return $this->respond($this->voting->finalize($request->user(), $movieNight, $candidateId));
    }

    private function validateId(Request $request, string $field, bool $required = true): ?int
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $unexpected = array_diff(array_keys($body), [$field]);

        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field is not allowed.'));
        }

        $validated = Validator::make($body, [
            $field => $required ? ['required', 'integer', 'min:1'] : ['sometimes', 'required', 'integer', 'min:1'],
        ])->validate();

        return isset($validated[$field]) ? (int) $validated[$field] : null;
    }

    private function respond(array $summary, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $summary], $status)->header('Cache-Control', 'private, no-store');
    }
}
