# Nearby cinema search

## Local setup

1. Create a Geoapify project and enable Places API access for its key.
2. Set `GEOAPIFY_API_KEY` in the Laravel `backend/.env` file. Keep the key on the server and leave it out of Git and Vite configuration.
3. Run `php artisan config:clear` from `backend` after changing local configuration.

[Geoapify Places documentation](https://apidocs.geoapify.com/docs/places/)

## API contract

`GET /api/v1/cinemas/nearby` requires a signed-in, enabled account. All three query parameters are required.

| Parameter | Accepted values |
| --- | --- |
| `lat` | Latitude between -90 and 90 |
| `lng` | Longitude between -180 and 180 |
| `radius` | Whole meters between 1 and 50,000 |

Example request using central Beirut as the search center.

```http
GET /api/v1/cinemas/nearby?lat=33.8938&lng=35.5018&radius=10000
Accept: application/json
```

A successful response contains a `data` array. The following result illustrates the response fields.

```json
{
  "data": [
    {
      "id": "provider-place-id",
      "name": "Example Cinema",
      "address": "Example Street, Beirut",
      "latitude": 33.894,
      "longitude": 35.502
    }
  ]
}
```

The `id` is Geoapify's place ID. The address can be `null`. An empty search returns HTTP 200 with `{"data":[]}`. Signed-out requests return 401, invalid parameters return 422, and the per-user rate limit returns 429. Missing configuration, timeouts, upstream errors, and invalid provider responses return 503 with a generic message.

## Usage and attribution

Laravel permits 10 searches per minute per user. Each valid search makes one Places API request for up to 20 results and uses a four-second total timeout. Geoapify charges one credit per 20 Places results and advertises 3,000 credits per day on its free plan. Usage is shared with the project's other Geoapify requests. Check the project's dashboard and current pricing for its limits. [Places API usage](https://apidocs.geoapify.com/docs/places/places) Â· [Geoapify pricing](https://www.geoapify.com/pricing/)

When displaying results, include OpenStreetMap contributor credit and the Geoapify attribution required by your plan. Geoapify's free plan requires a visible, followable Geoapify link. [OpenStreetMap copyright](https://www.openstreetmap.org/copyright) Â· [Geoapify terms](https://www.geoapify.com/terms-and-conditions/)
