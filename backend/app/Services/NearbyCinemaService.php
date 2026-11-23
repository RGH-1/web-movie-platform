<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class NearbyCinemaService
{
    private const UNAVAILABLE = 'Nearby cinema search is temporarily unavailable.';

    public function nearby(float $latitude, float $longitude, int $radius): array
    {
        $key = config('services.geoapify.key');
        abort_unless(is_string($key) && trim($key) !== '', 503, self::UNAVAILABLE);

        $center = "$longitude,$latitude";

        try {
            $response = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->withoutRedirecting()
                ->get('https://api.geoapify.com/v2/places', [
                    'categories' => 'entertainment.cinema',
                    'filter' => "circle:$center,$radius",
                    'bias' => "proximity:$center",
                    'limit' => 20,
                    'apiKey' => $key,
                ]);
        } catch (ConnectionException) {
            abort(503, self::UNAVAILABLE);
        }

        abort_unless($response->successful(), 503, self::UNAVAILABLE);

        $features = $response->json('features');
        abort_unless(
            $response->json('type') === 'FeatureCollection' && is_array($features) && array_is_list($features),
            503,
            self::UNAVAILABLE,
        );

        $cinemas = [];

        foreach ($features as $feature) {
            $name = data_get($feature, 'properties.name');
            $id = data_get($feature, 'properties.place_id');
            $longitude = data_get($feature, 'geometry.coordinates.0');
            $latitude = data_get($feature, 'geometry.coordinates.1');

            if (data_get($feature, 'geometry.type') !== 'Point'
                || ! is_string($id) || trim($id) === ''
                || ! is_string($name) || trim($name) === ''
                || ! is_numeric($latitude) || abs((float) $latitude) > 90
                || ! is_numeric($longitude) || abs((float) $longitude) > 180) {
                continue;
            }

            $address = data_get($feature, 'properties.formatted');

            $cinemas[] = [
                'id' => $id,
                'name' => trim($name),
                'address' => is_string($address) && trim($address) !== '' ? trim($address) : null,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        return $cinemas;
    }
}
