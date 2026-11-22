# Recommendations (AB#116)

From the signed-in homepage, open **Recommendations**. The page starts
without location. Choosing **Use my location** asks the browser for permission
and sends coordinates rounded to two decimals. **Use city** resolves a typed
city through Laravel and sends the resulting coordinates to the recommendation
endpoint. **Browse without location** clears the applied location. The page
keeps the selection in memory for the current visit.

Both requests go through the shared Axios client with `Accept: application/json`
and the existing Sanctum session cookie, with no request body.

| Purpose | Request |
| --- | --- |
| Start without weather | `GET /api/v1/recommendations` |
| Resolve a city | `GET /api/v1/recommendations/city?city=Beirut%2C%20Lebanon` |
| Get weather-aware recommendations | `GET /api/v1/recommendations?latitude=33.89&longitude=35.50` |

Laravel ranks the movies and returns the evidence for the explanation under
each movie. Weather data comes from [Open-Meteo](https://open-meteo.com/), with
city locations based on [GeoNames](https://www.geonames.org/). See the
[weather API guide](../api/weather-service-integration.md) for city matching and
the [recommendation contract](../api/recommendations-contract.md) for ranking
response fields.

## Verify locally

Start the Laravel API at `http://localhost:8000` and Vite at
`http://localhost:5173`. Use an account with a complete profile. For movie
results, have eligible unwatched and unrated movies in the local catalog or
a configured TMDB connection.

1. From the signed-in homepage, click **Recommendations**. Expect a loading
   message followed by **Picked for you** or **General recommendations**. The
   browser should ask for location only after you click **Use my location**.
   Open a movie using **View Details** and check the explanations on the cards.
2. Return to `/recommendations`, click **Use my location**, and allow access.
   With weather available, the page shows the approximate device location,
   weather condition, and temperature in °C. Repeat with location blocked in
   browser settings. Recommendations should remain usable.
3. Type **Beirut, Lebanon** in **City** and click **Use city**. Expect weather
   for that city when the provider responds. Enter an invented city and verify
   you get a city lookup message and recommendations without weather. Click
   **Browse without location** to return to the default mode.
4. For error and retry, block only the regular `/api/v1/recommendations`
   request in DevTools. Open the page, wait for the failure message, unblock
   the request, then click **Try again**. With a test catalog containing no
   eligible local or provider movies, expect the empty-state message.
5. At a narrow phone width and a desktop width, check that city controls and
   cards fit the viewport and the **View Details** links remain usable.
