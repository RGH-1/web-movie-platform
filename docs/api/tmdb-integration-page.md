# Temporary TMDB Integration Page

Azure Boards task: AB#19

## Purpose

Verify React can retrieve movie search results through Laravel.
The existing TmdbService handles TMDB requests; the token remains
in backend/.env.

## Setup

Follow docs/development-setup.md, including Step 8 for TMDB credentials.

Set the frontend API address in frontend/.env.local:

VITE_API_BASE_URL=http://127.0.0.1:8000/api

Run php artisan serve from backend and npm run dev from frontend.
Open the address printed by Vite.

## Endpoint

GET /api/integration/tmdb/search?query=Interstellar

The query is required and limited to 200 characters.

Responses:
- 200: movie results, including an empty array when nothing matches.
- 422: invalid search input.
- 503: TMDB service unavailable.

## Verification

- Backend tests passed, including four endpoint tests.
- Frontend lint and production build passed.
- Searching Interstellar displayed titles and available posters.
- Loading feedback appeared during the request.
- A search with no matches displayed the empty-result message.
- A connection failure displayed an error without crashing.
- A subsequent successful search restored movie results.


## Replacement

Remove or repurpose the temporary component and endpoint when the
proper movie-search interface is implemented. Update the associated
tests and documentation at that time.