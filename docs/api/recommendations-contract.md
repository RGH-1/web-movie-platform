# Recommendation API contract — AB#112

Status: Proposed; backend/frontend approval pending.
Algorithm version: weighted-v1.
Endpoint implementation is outside this design document.

## Endpoint

GET /api/v1/recommendations

Access:

- Sanctum authentication required.
- Complete profile required.
- No administrator role required.
- The authenticated user supplies the identity; user_id is not accepted.

The endpoint reads application data and may call external providers.
It does not persist catalog imports, weather locations, or user history.

## Request

| Parameter | Required | Rules |
| --- | --- | --- |
| limit | No | Integer 1–20; default 12 |
| latitude | No | Finite number from -90 through 90 |
| longitude | No | Finite number from -180 through 180 |

Coordinates must be supplied together.

Omitting both coordinates requests recommendations without weather.
Malformed or incomplete coordinate pairs return 422.

Reject unsupported parameters, including user_id, weights, weather_code,
temperature, provider_url, page, and arbitrary candidate IDs.

Weather conditions are obtained by the server. The client cannot submit a
weather score or override weights.

Examples:

```text
GET /api/v1/recommendations?limit=12
GET /api/v1/recommendations?limit=12&latitude=33.89&longitude=35.50
```

Coordinates above are illustrative, not a default user location.

There is no pagination in weighted-v1. The response is a bounded ranked
selection. Do not return a misleading total matching the entire movie catalog.

## Weather location handling

React offers an explicit option to use current weather.

Only obtain browser location after the user chooses that option.
If permission is denied or location cannot be obtained, request
recommendations without coordinates.

Do not silently assume a city.

Round coordinates to two decimal places before sending them to the provider.
Use those same rounded coordinates for weather caching.

Do not persist coordinates or return them in recommendation responses.
Redact latitude and longitude from request logging and analytics.

Location is a weather lookup hint, not an authentication or authorization
input.

## Successful response

Return 200 with:

- data: ordered movie cards with recommendation details.
- meta: request-level ranking and fallback context.

Successful responses use Cache-Control: private, no-store.

The following is a synthetic example for the rainy worked example, requested
with limit=1. IDs and timestamps do not identify verified repository records.

```json
{
  "data": [
    {
      "local_movie_id": "42",
      "tmdb_id": 12345,
      "source": "tmdb",
      "is_active": true,
      "title": "Candidate A",
      "overview": "Synthetic example movie.",
      "poster_url": null,
      "release_date": "2020-01-01",
      "vote_average": 7.6,
      "genres": [
        {
          "tmdb_id": 9648,
          "name": "Mystery"
        }
      ],
      "platform_rating": {
        "average": 3.8,
        "count": 10
      },
      "recommendation": {
        "score": 72.2,
        "signals": {
          "genre": {
            "value": 0.5,
            "weight": 0.25,
            "points": 12.5,
            "evidence": "observed"
          },
          "quality": {
            "value": 0.76,
            "weight": 0.2,
            "points": 15.2,
            "evidence": "observed"
          },
          "history": {
            "value": 1,
            "weight": 0.2,
            "points": 20,
            "evidence": "observed"
          },
          "people": {
            "value": 0.5,
            "weight": 0.15,
            "points": 7.5,
            "evidence": "observed"
          },
          "similarity": {
            "value": 0.7,
            "weight": 0.1,
            "points": 7,
            "evidence": "observed"
          },
          "weather": {
            "value": 1,
            "weight": 0.1,
            "points": 10,
            "evidence": "observed"
          }
        },
        "reasons": [
          {
            "code": "weather_match",
            "message": "Weather boost: mystery in rainy conditions.",
            "params": {
              "condition": "rainy",
              "genres": ["mystery"]
            }
          },
          {
            "code": "watched_history",
            "message": "Matches genres in your watched history.",
            "params": {
              "genres": ["mystery"]
            }
          },
          {
            "code": "community_rating",
            "message": "Rated 3.8 out of 5 across 10 visible ratings.",
            "params": {
              "average": 3.8,
              "count": 10
            }
          }
        ]
      }
    }
  ],
  "meta": {
    "algorithm_version": "weighted-v1",
    "requested_limit": 1,
    "returned_count": 1,
    "candidate_count": 2,
    "personalization": "personalized",
    "partial": false,
    "warnings": [],
    "empty_reason": null,
    "weather": {
      "status": "available",
      "condition": "rainy",
      "temperature_c": 14,
      "valid_at": "2026-11-21T15:00:00Z"
    },
    "generated_at": "2026-11-21T15:05:00Z"
  }
}
```

## Field rules

Movie card:

- local_movie_id: nullable decimal string.
- tmdb_id: nullable positive provider integer.
- At least one of these identities must exist.
- source: local or tmdb.
- is_active: true for returned results.
- title: nonblank string.
- overview, poster_url, release_date, vote_average: nullable.
- vote_average uses the TMDB 0–10 scale.
- genres: array, possibly empty.
- platform_rating.average: nullable visible platform mean on the 1–5 scale.
- platform_rating.count: nonnegative integer.
- count=0 implies average=null.

Recommendation:

- score: number from 0 through 100, rounded to two decimals.
- signals: always contains genre, quality, history, people, similarity,
  and weather.
- value: number 0–1, or null for an inactive signal.
- weight: effective normalized weight 0–1.
- points: contribution on the 0–100 score scale.
- evidence: observed, partial_metadata, missing_metadata, prior, or unavailable.
- Inactive signals use value=null, weight=0, points=0, evidence=unavailable.
- Effective weights sum to 1 within floating-point tolerance.
- Score equals the sum of unrounded signal points, then rounded.
- Displayed rounded points may differ from the displayed total by a few
  hundredths because of independent rounding.
- reasons: at most three evidence-backed entries.

A mixed known/missing composite signal uses partial_metadata.

Reason codes:

- genre_preference
- community_rating
- tmdb_rating
- watched_history
- people_match
- similar_to_liked
- weather_match
- general_discovery

Reason messages are plain text. Render them as text, never HTML.
The frontend may use code/params for presentation and must tolerate
unrecognized future reason codes.

Request metadata:

- candidate_count: eligible candidates actually scored in this bounded pool.
- personalization: personalized if G, H, P, or S is active; otherwise general.
- partial: true when a provider/configuration/budget failure affected
  planned retrieval or requested weather.
- warnings: unique machine-readable codes.
- empty_reason: null when data is nonempty; otherwise no_eligible_candidates
  or sources_unavailable.
- weather.status: available, not_requested, or unavailable.
- Unavailable/not-requested weather uses null condition, temperature_c,
  and valid_at.
- generated_at and valid_at: UTC ISO 8601 timestamps.

## Degraded responses

Return useful cached/local recommendations with 200 when possible.

Warning codes:

- TMDB_UNAVAILABLE
- TMDB_NOT_CONFIGURED
- WEATHER_UNAVAILABLE
- PROVIDER_BUDGET_EXHAUSTED
- ENRICHMENT_INCOMPLETE

Optional weather not requested is normal operation:
weather.status=not_requested, no weather warning, and partial=false unless
another retrieval problem occurred.

Provider failures with no remaining candidates still return 200, data=[],
partial=true, and empty_reason=sources_unavailable.

An ordinary empty eligible pool returns 200, data=[], partial=false, and
empty_reason=no_eligible_candidates.

Unexpected application/database failures remain server errors. Do not
disguise them as successful empty recommendations.

## Errors and limits

| Status | Meaning |
| --- | --- |
| 401 | Authentication required |
| 403 | Profile incomplete, following the existing profile contract |
| 422 | Invalid or unsupported query parameters |
| 429 | User endpoint request limit exceeded |
| 500 | Unexpected application or database failure |

The endpoint limit is 10 requests/minute per authenticated user.
A 429 response includes Retry-After seconds.

Provider attempt limits, caches, and deadlines are defined in the algorithm
document. No client refresh parameter bypasses those controls.

## Frontend integration agreement

Use the shared Axios client:

```javascript
api.get('/v1/recommendations', {
    params: {
        limit: 12,
        ...(location
            ? {
                latitude: location.latitude,
                longitude: location.longitude,
            }
            : {}),
    },
    signal,
});
```

This snippet specifies the future call contract. It is not an implemented
frontend service.

The configured base URL already includes /api.

Preserve backend order. Do not independently rerank movies or apply a second
weather filter in React.

Reuse MovieCard for the existing card fields, with a recommendation wrapper
for explanations and optional score display.

Keep local_movie_id as a string. Preserve existing TMDB and local detail
routes.

Label any displayed recommendation score as a match score, separate from
platform star ratings and TMDB ratings.

Handle:

- Loading and aborted requests.
- Populated recommendations with explanation text.
- Empty eligible pool.
- Useful results with partial-source warnings.
- Weather unavailable without losing the movie list.
- Location permission denied without blocking ordinary recommendations.
- 401 sign-in, 403 profile-completion, and 429 retry states.
- General recommendations without claiming learned personalization.

Do not automatically retry failed requests in a loop or refetch on every
render. Explicit refreshes remain subject to backend limits.

## Agreement record

Do not mark approval from document creation alone.

| Responsibility | Decision |
| --- | --- |
| Backend owner | Pending |
| Frontend owner | Pending |
| Endpoint and query parameters | Proposed above |
| Identity, score, explanation, and fallback fields | Proposed above |

Record the actual approving people and date after review.