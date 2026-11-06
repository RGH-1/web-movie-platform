# Movie details API contract

Parent story: View Movie Details  
Work item: AB#50 — F1 — Define the movie details API contract  
Status: Proposed for team review

## Endpoint

`GET /api/v1/movies/{tmdbId}`

This endpoint is public. Send `Accept: application/json`. The request
takes no body or query parameters. Use the `tmdb_id` from a
[movie search result](movie-search-contract.md) as the path parameter.

Example:

```http
GET /api/v1/movies/157336
Accept: application/json
```

The path segment must contain digits. The value must be a positive
integer in the range `1` through `2147483647`, matching TMDB's
signed 32-bit movie ID parameter. Validate the value before calling TMDB.

## Successful response

Return `200 OK` with one movie object under `data`. Every field listed
below is present. Use `null` for unavailable scalar values or a
missing trailer, and `[]` for an empty genre or cast list.

```json
{
  "data": {
    "tmdb_id": 157336,
    "title": "Interstellar",
    "overview": "A team travels beyond Earth in search of a new home.",
    "release_date": "2014-11-05",
    "runtime_minutes": 169,
    "genres": [
      { "tmdb_id": 878, "name": "Science Fiction" },
      { "tmdb_id": 12, "name": "Adventure" }
    ],
    "poster_url": "https://image.tmdb.org/t/p/w342/example-poster.jpg",
    "backdrop_url": "https://image.tmdb.org/t/p/w780/example-backdrop.jpg",
    "vote_average": 8.4,
    "cast": [
      {
        "tmdb_person_id": 10297,
        "name": "Matthew McConaughey",
        "character": "Cooper",
        "profile_url": null
      },
      {
        "tmdb_person_id": 1813,
        "name": "Anne Hathaway",
        "character": "Brand",
        "profile_url": null
      }
    ],
    "director": "Christopher Nolan",
    "trailer": {
      "site": "YouTube",
      "key": "zSWdZVtXT7E"
    }
  }
}
```

The example is illustrative. Image filenames are placeholders.

| Field | Type | Meaning |
| --- | --- | --- |
| `tmdb_id` | Integer | TMDB movie ID. Matches the ID in search results and the requested ID. |
| `title` | String | Non-empty display title. |
| `overview` | String or `null` | Synopsis. Blank or missing text becomes `null`. |
| `release_date` | String or `null` | Date in `YYYY-MM-DD` format. |
| `runtime_minutes` | Integer or `null` | Runtime in minutes. Missing or nonpositive values become `null`. |
| `genres` | Array | Each entry has an integer `tmdb_id` and a string `name`. |
| `poster_url` | String or `null` | Full HTTPS poster URL. Same format as movie search. |
| `backdrop_url` | String or `null` | Full HTTPS backdrop URL. |
| `vote_average` | Number or `null` | TMDB rating on a 0–10 scale. Use `null` when no votes or usable rating are available. |
| `cast` | Array | Up to eight principal cast members in TMDB billing order. |
| `director` | String or `null` | First named crew member credited as `Director` in TMDB crew order. |
| `trailer` | Object or `null` | One playable YouTube trailer. The object has `site` and `key` string fields. |

Each cast entry has an integer `tmdb_person_id`, a string `name`, and
two nullable strings, `character` and `profile_url`. Use `null` for a
missing character name or profile image. Build all image URLs in Laravel.

For the trailer, consider videos whose `site` is `YouTube`, whose `type`
is `Trailer`, and whose `key` is a valid YouTube video key. Pick the
first official match in TMDB response order. If there is no official
match, pick the first eligible match. Return `null` if none qualify.

React can hide the trailer player when `trailer` is `null`, display an
image placeholder when a URL is `null`, and hide a cast or genres
section when its list is empty. A movie with missing optional
information still returns `200`.