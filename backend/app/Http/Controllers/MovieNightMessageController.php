<?php

namespace App\Http\Controllers;

use App\Http\Resources\MovieNightMessageResource;
use App\Models\MovieNight;
use App\Models\MovieNightMessage;
use App\Services\MovieNightAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MovieNightMessageController extends Controller
{
    public function index(Request $request, MovieNight $movieNight, MovieNightAccessService $access): JsonResponse
    {
        $messages = $access->forViewing(
            $request->user(),
            $movieNight,
            fn (MovieNight $night) => $night->messages()->orderByDesc('id')->cursorPaginate(50),
        );

        return MovieNightMessageResource::collection($messages)->response()
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, MovieNight $movieNight, MovieNightAccessService $access): JsonResponse
    {
        $sender = $request->user();
        $message = $access->forMessage($sender, $movieNight, function (MovieNight $night) use ($request, $sender): MovieNightMessage {
            $data = $request->validate([
                'text' => ['required', 'string', 'max:1000'],
                'video_timestamp_seconds' => ['nullable', 'numeric', 'min:0'],
                'is_spoiler' => ['sometimes', 'boolean'],
            ]);

            $timestamp = $data['video_timestamp_seconds'] ?? null;
            if ($timestamp !== null) {
                $duration = $night->videoAsset?->duration_seconds;
                if ($duration === null || ! is_finite((float) $timestamp) || (float) $timestamp > $duration) {
                    throw ValidationException::withMessages([
                        'video_timestamp_seconds' => 'The playback timestamp must be within the assigned video duration.',
                    ]);
                }
            }

            $message = new MovieNightMessage($data + ['is_spoiler' => false]);
            $message->sender_id = $sender->getKey();

            return $night->messages()->save($message);
        });

        return (new MovieNightMessageResource($message))->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'private, no-store');
    }
}
