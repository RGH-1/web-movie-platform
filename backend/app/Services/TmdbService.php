<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TmdbService
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $baseUrl = config('services.tmdb.base_url');
        $token = config('services.tmdb.token');

        if (
            !is_string($baseUrl) || trim($baseUrl) === '' ||
            !is_string($token) || trim($token) === ''
        ) {
            throw new RuntimeException(
                'TMDB API configuration is missing.'
            );
        }

        $this->baseUrl = $baseUrl;
        $this->token = $token;
    }

    public function get(string $endpoint, array $query = []): Response
    {
        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get(
                    rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/'),
                    $query
                );
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Unable to connect to the movie information service.',
                0,
                $exception
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'The movie information service returned an error.'
            );
        }

        return $response;
    }
}
