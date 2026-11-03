<?php

namespace App\Http\Controllers;
use Illuminate\Http\JsonResponse;
use App\Services\TmdbService;
use Illuminate\Http\Request;

class TmdbIntegrationController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate(['query' => ['required','string','max:200'],]);

        try{
            $service=app(TmdbService::class);

            $response=$service->get('/search/movie',
            [
                'query'=>$validated['query'],
                'language'=>'en-US',
                'include_adult'=>false,
            ]);
            return response()->json(['results'=>$response->json('results',[]),]);

        }catch(\RuntimeException $exception){
            report($exception);
            return response()->json(['message' => 'Movie search is unavailable. Please try again later.'],503);
        }

    }


}
