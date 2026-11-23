# Multi-signal recommendation algorithm — AB#112

Algorithm version: weighted-v1
Status: Proposed design; shared contract approval pending.

## Scope and architecture

Implement recommendations within the existing Laravel backend using service
classes and the existing TMDB integration.

React requests ranked results from Laravel. Laravel loads the authenticated
user's preferences/history, builds candidates, obtains optional weather,
scores candidates, and returns explanations.

The recommendation request does not import movies, refresh persisted
catalog metadata, or change user history.

Weather participates in the same final score as the other signals.

The initial weights and weather-to-genre associations are explicit product
heuristics. They are not learned preferences or predictions of enjoyment.

## Existing data sources

| Input | Source |
| --- | --- |
| Explicit preferences | users.favorite_genres |
| Personal ratings | Authenticated user's visible reviews |
| Community ratings | Visible reviews grouped by canonical movie_id |
| Watched history | Authenticated user's user_movie_statuses.watched_at |
| Movie metadata | Active catalog records and normalized TMDB metadata |
| Actor identifiers | cast[].tmdb_person_id where available |
| Director | Existing director name string |
| Weather | Optional server-side current-weather lookup |

Ignore hidden reviews when constructing rating-derived preferences and
community aggregates.

The master and category sharing preferences govern public-profile and
following-feed activity. Recommendations use
the requesting user's own data and do not expose another user's history.

Sentiment classification is not a scoring input in weighted-v1.

## Identity and metadata normalization

- movies.id is the canonical relationship identity.
- local_movie_id remains a decimal string in responses and React.
- tmdb_id is a provider identifier, never a substitute for movies.id.
- A candidate may be an unmapped TMDB movie with local_movie_id = null.
- A local-only candidate has tmdb_id = null.
- Deduplicate mapped movies by canonical identity and unmapped movies by
  tmdb_id. Never deduplicate by title.
- For an existing mapping, use the stored catalog metadata, including
  administrator changes, rather than replacing it with a feed card.
- Reject inactive mappings even if TMDB returns them again.

Normalize supported genres using this fixed mapping:

| Slug | TMDB genre ID | Name |
| --- | --- | --- |
| action | 28 | Action |
| adventure | 12 | Adventure |
| animation | 16 | Animation |
| comedy | 35 | Comedy |
| crime | 80 | Crime |
| documentary | 99 | Documentary |
| drama | 18 | Drama |
| fantasy | 14 | Fantasy |
| horror | 27 | Horror |
| mystery | 9648 | Mystery |
| romance | 10749 | Romance |
| science-fiction | 878 | Science Fiction |
| thriller | 53 | Thriller |
| family | 10751 | Family |
| history | 36 | History |
| music | 10402 | Music |
| tv-movie | 10770 | TV Movie |
| war | 10752 | War |
| western | 37 | Western |

AB#115 stores explicit profile preferences as supported TMDB integer IDs.
Normalize those IDs to the table's slugs before candidate selection and
scoring. Catalog names and genre objects remain supported inputs.

The scorer and explanation parameters continue using normalized slugs.
The signal weights and weather formula are unchanged. Genres without a
specific weather association use the existing neutral weather value.

Prefer a recognized genre ID. For local metadata without an ID, accept a
matching normalized supported name or slug.

For name matching, trim, collapse whitespace, and compare case-insensitively.
Treat "Science Fiction" and "science-fiction" as the same supported genre.

Preserve other valid genre metadata for display, but unsupported genres do
not establish preference matches or receive special weather boosts.

Use unique genre sets when scoring.

## Candidate selection

### Exclusions

Apply exclusions before pool limits where possible, and recheck them before
returning results.

Exclude:

1. Inactive catalog movies, including inactive TMDB mappings.
2. Movies the requesting user has marked watched.
3. Movies for which the requesting user already has any review, including
   a hidden review.
4. Known future releases: release_date later than the current UTC date.
5. Invalid candidates without a valid identity or nonblank title.
6. Duplicates.

A missing release date does not automatically exclude a movie. Do not claim
that a recommendation establishes streaming availability.

Use all watched/reviewed rows for exclusions, not only the bounded rows used
to build preference signals.

A watchlist entry alone does not exclude a movie. Recommendations may help
the user choose something already saved for later.

### Local pool: maximum 200 candidates

From eligible active catalog movies, take the union of:

- Up to 100 matching explicit-preference or positive watched-history genres,
  ordered by updated_at descending, then canonical ID descending.
- Up to 100 ordered by the quality score defined below, then canonical ID
  descending.

Remove duplicates. Fill remaining places up to 200 using eligible catalog
movies ordered by canonical ID descending.

If there are no usable affinity genres, skip the genre bucket and fill from
the other buckets.

Canonical IDs must use numeric ordering in SQL, not lexical string ordering.

### Provider pool: maximum 60 candidates

When configured and within budget, request at most:

1. One discovery page for the primary affinity genre.
2. One broad discovery page sorted by popularity.
3. One similar-movies page for the strongest positive TMDB anchor.

The primary affinity genre is:

- First valid explicit preference, preserving stored preference order; or
- Highest watched-history genre affinity, breaking ties by genre slug.

Skip the personalized discovery call when there is no primary affinity.
Skip the similar-movies call when there is no positive TMDB anchor.

Each provider source contributes at most its first 20 valid cards. Do not
crawl additional pages to fill gaps.

Similar-movies results are candidate sources, not final recommendations.
Every returned candidate must pass exclusions and receive the application
score.

TMDB similar-movie retrieval requires a new wrapper in TmdbService.
Discovery and similar-card normalization must expose genre IDs for scoring.

### Merge and enrich

Merge the local and provider pools, deduplicate, and preserve local metadata
authority for known mappings.

Maximum pool size before enrichment: 260 candidates.

Compute preliminary scores using available metadata. Enrich at most three
unmapped TMDB candidates, chosen by preliminary rank, using movie details
with credits included.

Do not fetch details for every candidate. Do not refresh known mappings as
part of recommendation generation.

Score the complete pool again after enrichment, then apply final exclusions
and ranking.

Missing candidate metadata uses the documented neutral values; it never
causes additional unbounded provider requests.

## Bounded user context

Build signals from:

- Up to 100 most recent watched records, ordered by watched_at descending,
  then status ID descending.
- Up to 20 visible personal reviews rated 4 or 5, ordered by rating
  descending, created_at descending, then review ID descending.

Only active catalog movies with usable metadata contribute preference
features.

Use created_at rather than updated_at for positive-review ordering because
moderation also changes review timestamps.

Fetch related movies and relevant visible personal ratings in batches.
Do not issue a database or provider query for each history record.

## Scoring model

Each active signal has a value from 0 through 1.

| Signal | Key | Base weight |
| --- | --- | --- |
| Explicit genre preference | genre | 25 |
| Movie rating quality | quality | 20 |
| Watched-history affinity | history | 20 |
| Actor/director affinity | people | 15 |
| Similarity to positive anchors | similarity | 10 |
| Current weather | weather | 10 |

Signal availability is decided for the user/request, consistently across
all candidates.

Let active_weight be the sum of active base weights.

effective_weight_i = base_weight_i / active_weight

points_i = 100 * effective_weight_i * signal_i

final_score = sum(points_i)

Use full precision for ranking. Round the displayed score and points to
two decimal places.

An inactive signal has value null, effective weight 0, and points 0.

When an active signal lacks a candidate's required metadata, use the neutral
value 0.5 for that missing feature and identify it as missing metadata.
Do not remove its weight for only that candidate.

Quality has its own documented prior and is always active.

The score is a ranking index, not a predicted star rating or probability.

## Signal G: explicit genre preferences

Available when the user has at least one valid favorite genre.

G = min(
    1,
    matching_preference_count / min(2, favorite_genre_count)
)

Example: favorites [science-fiction, mystery] and a candidate containing
mystery gives G = 0.5. Matching both gives G = 1.

Known genres with no match give 0. Missing genre metadata gives 0.5.

## Signal Q: rating quality

Use visible platform reviews only.

Let:

- n = visible platform rating count for this candidate.
- R = visible platform mean divided by 5.
- T = valid normalized TMDB vote_average divided by 10.
- prior = T when available, otherwise 0.6.
- prior_strength = 5.

Q = (n * R + prior_strength * prior) / (n + prior_strength)

When n = 0, Q equals prior. Do not calculate R for an empty set.

Examples:

- No platform ratings, TMDB score 8/10: Q = 0.8.
- No ratings from either source: Q = 0.6.
- Ten platform ratings averaging 3.8/5, TMDB 7.6/10: Q = 0.76.

This avoids treating a single five-star review as a perfect quality score.

Do not depend on stored TMDB vote_count: the current catalog does not retain
that field. TMDB vote_average must already satisfy the existing service's
validity rules.

Public rating_average remains the visible platform mean. Q is a separate
recommendation input and must not replace that displayed platform mean.

## Signal H: watched-history affinity

For each selected watched movie, assign an evidence weight:

| Visible personal rating | Evidence weight |
| --- | --- |
| 5 | 1.00 |
| 4 | 0.75 |
| 3 | 0.25 |
| 1 or 2 | 0.00 |
| No visible personal rating | 0.50 |

Watching a movie alone is weak evidence, not proof that the user liked it.

For each supported genre g:

count_g = sum of evidence weights for watched movies containing g

affinity_g = count_g / max(all count_g values)

H = mean affinity_g across the candidate's supported genres

Genres absent from the history receive affinity 0.

If all evidence counts are zero, the history signal is inactive.
If the candidate has no usable genres while the signal is active, H = 0.5.

A movie with an explicit low visible rating contributes no positive
watched-history evidence.

## Signal P: actor/director affinity

Use the bounded positive-review anchors.

Each anchor receives weight:

anchor_weight = (rating - 3) / 2

Thus a four-star anchor has weight 0.5 and a five-star anchor has weight 1.

For each actor:

actor_affinity = sum of matching anchor weights /
                 sum of anchor weights with usable cast

For each director:

director_affinity = sum of matching anchor weights /
                    sum of anchor weights with a usable director

Actor match for a candidate is the maximum affinity among its cast members.
Director match is the affinity for its director.

P is the mean of the user-supported actor/director dimensions.
A user-supported dimension with missing candidate metadata contributes 0.5.
Known metadata without a match contributes 0.

If neither dimension has usable positive-anchor evidence, P is inactive.

Match actor IDs when both sides have IDs. Where an ID is absent, normalized
name matching may be used, but it is a documented approximation.

Director matching currently uses normalized names because the catalog
stores a director string rather than a person ID.

Do not make person-search requests to calculate this signal.

## Signal S: similarity to positively rated movies

Use up to three positive anchors with usable genre metadata, taking their
existing positive-anchor order.

For a candidate and an anchor:

J = size(genre intersection) / size(genre union)

runtime_similarity = max(0, 1 - abs(runtime difference in minutes) / 60)

year_similarity = max(0, 1 - abs(release-year difference) / 20)

pair_similarity =
    0.60 * J +
    0.20 * runtime_similarity +
    0.20 * year_similarity

S = maximum pair_similarity across the selected anchors

Use 0.5 for a missing pair feature. A missing genre set gives J = 0.5.

If no positive anchor has usable genres, S is inactive.

This is deterministic content similarity. Provider similar-movie membership
is not itself a score and does not bypass the scoring formula.

## Signal W: current weather

Weather is optional. Missing location or weather must not prevent useful
recommendations.

Proposed provider: Open-Meteo, called by Laravel through a weather adapter.

Request current temperature_2m and weather_code, Celsius units, UTC timestamps.

Reject unusable responses, including unknown weather codes, invalid
temperature, missing timestamps, timestamps more than 60 minutes old, or
timestamps more than five minutes in the future.

Provider current conditions are model-derived conditions. Describe the
timestamp as valid_at, not as a sensor observation.

Normalize conditions in this priority order:

1. snowy: codes 71, 73, 75, 77, 85, 86
2. rainy: codes 51, 53, 55, 56, 57, 61, 63, 65, 66, 67,
   80, 81, 82, 95, 96, 99
3. cold: remaining recognized codes with temperature <= 10 C
4. hot: remaining recognized codes with temperature >= 30 C
5. clear: remaining codes 0, 1, 2
6. cloudy: remaining codes 3, 45, 48

Recognized codes are the union of the code sets above.

The following table defines genre boosts. Genres not listed in a row receive
0.5:

| Condition | Genre values |
| --- | --- |
| rainy | mystery=1.0, drama=1.0, romance=0.8, thriller=0.8, comedy=0.6, science-fiction=0.4 |
| snowy | animation=1.0, fantasy=0.9, comedy=0.8, romance=0.8 |
| cold | drama=0.9, romance=0.9, fantasy=0.8, animation=0.8 |
| hot | comedy=1.0, adventure=0.9, animation=0.8, action=0.8 |
| clear | adventure=1.0, animation=0.9, comedy=0.8, action=0.8, science-fiction=0.6, mystery=0.4 |
| cloudy | mystery=0.8, drama=0.8, thriller=0.7 |

W = mean weather genre value across the candidate's usable genres

If candidate genres are unavailable, W = 0.5.

These associations are editorial rules for the project. They do not claim
that weather determines a person's mood.

If weather is unavailable, deactivate W and renormalize the other weights.
Do not substitute invented clear/rainy conditions.

## Fallbacks

| Missing input | Behavior |
| --- | --- |
| No favorite genres | Deactivate G |
| No useful watched history | Deactivate H |
| No positive rating anchors | Deactivate P and S |
| No platform ratings for candidate | Use TMDB quality prior, or 0.6 |
| No TMDB rating | Use 0.6 as the quality prior |
| No weather/location | Deactivate W |
| Candidate metadata missing | Use neutral values for affected active features |
| TMDB unavailable/unconfigured | Rank eligible local candidates |
| Weather provider fails | Continue without W |
| Candidate pool empty | Return 200 with data=[], never invent results |

A new user with no preferences/history uses quality plus weather when
available. Without weather, quality alone determines ranking.

If all candidates have the default quality prior, use the deterministic
tie-break rather than claiming personalization.

## Final ranking and explanations

Sort by:

1. Unrounded final score descending.
2. Canonical movie ID ascending for mapped/local candidates.
3. TMDB ID ascending for unmapped candidates.

When comparing a mapped and unmapped candidate with an equal score, mapped
candidates come first.

Return at most the requested limit. Do not pad with excluded movies.

Return up to three explanation reasons.

Reasons must be supported by actual evidence. Neutral missing-metadata
values must not produce claims about matching actors, directors, or genres.

If weather is active and W >= 0.7 with known genre evidence, reserve one
reason for weather. Fill other places using the highest-contributing
evidence-backed signals, breaking ties by signal order G,Q,H,P,S,W.

Use structured codes and parameters alongside plain-text messages.

Never disclose another user's ratings, identity, or private history.

## Provider and processing budgets

| Resource | Maximum |
| --- | --- |
| Requested result limit | 20; default 12 |
| Local candidates | 200 |
| Provider candidates | 60 |
| Positive review anchors | 20 |
| Similarity anchors | 3 |
| Watched-history feature rows | 100 |
| TMDB candidate-source attempts | 3 |
| TMDB detail-enrichment attempts | 3 |
| Total TMDB HTTP attempts per request | 6 |
| Weather HTTP attempts per request | 1 |
| Concurrent provider requests | 3 |
| Overall external-I/O deadline | 8 seconds |
| Per-call timeout | At most 2 seconds and never beyond remaining deadline |
| Automatic provider retries | 0 |

Failed attempts count against the budget. Cache hits do not.

The deadline may prevent all six TMDB attempts. Check the remaining deadline
before scheduling each call and stop scheduling when less than 500 ms remains.

These are application limits, not claims about provider quotas.

Proposed shared upstream-cache lifetimes:

- Discovery pages: 15 minutes
- Similar-movie pages: 60 minutes
- Normalized details: 24 hours
- Current weather: 10 minutes
- Provider failure cooldown: 60 seconds, or longer when Retry-After requires it

Use single-flight cache locks to prevent concurrent cache misses from
duplicating provider calls.

Round supplied coordinates to two decimal places before both requesting
weather and forming the weather-cache key.

Use an application-wide budget of 120 TMDB attempts/minute and 60 weather
attempts/minute, enforced atomically in shared storage. When exhausted,
continue with cached/local data.

Throttle the recommendation endpoint at 10 requests/minute per user.

Do not cache final personalized responses in weighted-v1. Rebuild scores
from current user context and current visible rating aggregates.

Recheck active mappings and exclusions even when using cached provider data.

Do not eagerly construct TmdbService when configuration is missing: its
current constructor throws. The recommendation orchestration must support
a local-only path.

The current TmdbService transport needs a recommendation-specific way to
honor the shorter deadline without changing existing endpoint behavior.

## Worked example

All movies, identities, ratings, and weather in this example are synthetic.

User:

- Favorite genres: science-fiction and mystery.
- Watched movie Alpha: genres science-fiction/mystery, rating 5,
  runtime 120 minutes, release year 2020, director D1, cast A/B.
- Watched movie Beta: comedy, no rating.
- Alpha is the only positive rating anchor.

History genre counts are:

- science-fiction: 1
- mystery: 1
- comedy: 0.5

After normalization, these are also their history affinities.

Candidates:

| Field | Candidate A | Candidate B |
| --- | --- | --- |
| Genre | mystery | science-fiction |
| Visible platform mean/count | 3.8/5 from 10 | 4/5 from 10 |
| TMDB rating | 7.6/10 | 8/10 |
| Runtime/year | 120 / 2020 | 120 / 2020 |
| Director | D2 | D2 |
| Cast | A/C | A/D |

Both candidates share an actor with Alpha and have a different director.

For both:

- G = 1 / 2 = 0.5
- H = 1
- Actor affinity = 1; director affinity = 0; P = 0.5
- Genre Jaccard with Alpha = 0.5
- Runtime similarity = 1
- Year similarity = 1
- S = 0.6*0.5 + 0.2*1 + 0.2*1 = 0.7

Quality:

- A: Q = (10*0.76 + 5*0.76) / 15 = 0.76
- B: Q = (10*0.80 + 5*0.80) / 15 = 0.80

| Contribution before weather | A | B |
| --- | ---: | ---: |
| Genre: 25*G | 12.50 | 12.50 |
| Quality: 20*Q | 15.20 | 16.00 |
| History: 20*H | 20.00 | 20.00 |
| People: 15*P | 7.50 | 7.50 |
| Similarity: 10*S | 7.00 | 7.00 |
| Subtotal | 62.20 | 63.00 |

Rainy conditions:

- A weather value = 1.0, contribution = 10.
- B weather value = 0.4, contribution = 4.
- Final scores: A = 72.20, B = 67.00.
- Ranking: A, B.

Clear conditions at a mild temperature:

- A weather value = 0.4, contribution = 4.
- B weather value = 0.6, contribution = 6.
- Final scores: A = 66.20, B = 69.00.
- Ranking: B, A.

Weather unavailable:

- Active base weight = 90.
- A = 100*62.2/90 = 69.11.
- B = 100*63/90 = 70.00.
- Ranking: B, A.

This demonstrates weather changing the final ranking while keeping the
candidate pool and all other inputs unchanged.

## References

- [TMDB similar movies](https://developer.themoviedb.org/reference/movie-similar)
- [Open-Meteo current weather and weather-code documentation](https://open-meteo.com/en/docs)