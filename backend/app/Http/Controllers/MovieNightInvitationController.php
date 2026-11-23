<?php

namespace App\Http\Controllers;

use App\Http\Resources\MovieNightInvitationResource;
use App\Models\MovieNight;
use App\Models\MovieNightInvitation;
use App\Services\MovieNightAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MovieNightInvitationController extends Controller
{
    public function store(Request $request, MovieNight $movieNight, MovieNightAccessService $access): JsonResponse
    {
        Gate::authorize('invite', $movieNight);

        $data = $this->validateBody($request, [
            'invitee_id' => ['bail', 'required', 'integer', 'min:1', Rule::exists('users', 'id')],
        ]);

        return $this->respondWith($access->invite($request->user(), $movieNight, (int) $data['invitee_id']), 201);
    }

    public function index(Request $request, MovieNight $movieNight): JsonResponse
    {
        $invitations = $movieNight->invitations()->with('invitee:id,name')->orderBy('id');
        $viewAll = Gate::allows('viewAllInvitations', $movieNight);

        if (! $viewAll) {
            $invitations->where('invitee_id', $request->user()->getKey());
        }

        $grouped = $invitations->get()->groupBy('status');
        abort_if(! $viewAll && $grouped->isEmpty(), 404, 'Movie night not found.');

        $data = [];
        foreach (MovieNightInvitation::STATUSES as $status) {
            $data[$status] = MovieNightInvitationResource::collection($grouped->get($status, collect()));
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function respond(Request $request, MovieNight $movieNight, MovieNightAccessService $access): JsonResponse
    {
        Gate::authorize('respondToInvitation', $movieNight);

        $data = $this->validateBody($request, [
            'status' => ['required', 'string', Rule::in([
                MovieNightInvitation::STATUS_ACCEPTED,
                MovieNightInvitation::STATUS_DECLINED,
            ])],
        ]);

        return $this->respondWith($access->respondToInvitation($request->user(), $movieNight, $data['status']));
    }

    private function validateBody(Request $request, array $rules): array
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $unexpected = array_diff(array_keys($body), array_keys($rules));

        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field is not allowed.'));
        }

        return Validator::make($body, $rules)->validate();
    }

    private function respondWith(MovieNightInvitation $invitation, int $status = 200): JsonResponse
    {
        return (new MovieNightInvitationResource($invitation->load('invitee:id,name')))
            ->response()
            ->setStatusCode($status)
            ->header('Cache-Control', 'private, no-store');
    }
}
