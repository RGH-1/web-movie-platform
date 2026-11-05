# Movie search API contract

Work item: AB#40 — Define the movie search endpoint and response format  
Status: Proposed for team review

## Endpoint

`GET /api/v1/movies/search`

Movie search is public. It accepts query parameters and returns JSON.
Send `Accept: application/json`. React calls Laravel, and Laravel calls
TMDB's `/search/movie` through `TmdbService`. The TMDB credential stays
on the backend.

The existing `/api/integration/tmdb/search` route serves the integration
test page. The discovery frontend uses the versioned endpoint documented
here.

## Query parameters

| Parameter | Required | Default | Validation |
| --- | --- | --- | --- |
| `query` | Yes | — | Trim surrounding whitespace. Require a string of 1–200 characters after trimming. Reject missing, empty, whitespace-only, or array values. Allow punctuation, accents, and non-Latin titles. |
| `page` | No | `1` | Require an integer from `1` to `500` when supplied. Reject blank, fractional, non-numeric, and out-of-range values. |

Only forward the validated `query` and `page` as client-supplied
parameters. Laravel sets `language=en-US` and `include_adult=false`
for the initial release. When the user changes the query, React starts
again at page `1`.

Examples:

```http
GET /api/v1/movies/search?query=Interstellar
GET /api/v1/movies/search?query=The%20Matrix&page=2
```

Laravel Form Request reference. Normalize a string `query` before
validation. Read these values from the query string, then default
`page` to `1` after validation when it is absent.

```php
return [
    'query' => ['bail', 'required', 'string', 'max:200'],
    'page' => ['sometimes', 'bail', 'required', 'integer', 'between:1,500'],
];
```

## Successful response

Return `200 OK` with a `data` array and a `meta` object. Preserve
TMDB's search result order. The example below uses illustrative data.

```json
{
  "data": [
    {
      "tmdb_id": 157336,
      "title": "Interstellar",
      "overview": "A team travels beyond Earth in search of a new home.",
      "release_date": "2014-11-05",
      "poster_url": "https://image.tmdb.org/t/p/w342/example-poster.jpg",
      "vote_average": 8.4
    }
  ],
  "meta": {
    "page": 1,
    "total_pages": 1,
    "total_results": 1
  }
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `tmdb_id` | Integer | The TMDB movie ID. React uses it as the result key and passes it to the future movie details route. |
| `title` | String | Display title returned by TMDB. |
| `overview` | String or `null` | Short description. Use `null` when missing or blank. |
| `release_date` | String or `null` | Release date in `YYYY-MM-DD` format. Use `null` when unavailable. |
| `poster_url` | String or `null` | Full HTTPS poster URL constructed by Laravel from the TMDB image base, size, and poster path. Use `null` when no poster is available. |
| `vote_average` | Number or `null` | TMDB's average rating on a 0–10 scale. Use `null` when the movie has no votes or no rating is available. |
| `meta.page` | Integer | The requested page, including when it has no results. |
| `meta.total_pages` | Integer | The number of accessible pages, capped at `500`. Use `0` when no movies match. |
| `meta.total_results` | Integer | The total matching movie count reported by TMDB. It may include matches beyond the accessible page limit. |

React can use `page > 1` for Previous and `page < total_pages` for Next.
When a valid requested page is past the last result page, return an
empty `data` array with the requested page and the search totals. The
frontend can offer to return to page `1`.

## No matching movies

Return `200 OK`. A search that finds nothing is a successful request
with an empty list.

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "total_pages": 0,
    "total_results": 0
  }
}
```

React shows a no-results message when `total_results` is `0`. If
`total_results` is positive and `data` is empty, show that the requested
page has no results.