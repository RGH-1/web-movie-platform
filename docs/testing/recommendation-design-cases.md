# Recommendation acceptance cases — AB#112

Status: Test specifications; runtime implementation and execution pending.

Use a fixed clock, synthetic catalog fixtures, fake TMDB responses, and fake
weather responses. Automated tests must not call live providers.

## Scoring unit cases

| Case | Expected result |
| --- | --- |
| Worked example, rainy | A=72.20, B=67.00; A ranks first |
| Same example, clear/mild | A=66.20, B=69.00; B ranks first |
| Same example, no weather | A=69.11, B=70.00; weather inactive |
| Two favorites, one candidate match | G=0.5 |
| Two favorites, two candidate matches | G=1 |
| Known candidate genres with no preference match | G=0 |
| Active genre signal, missing candidate genres | G=0.5; no fabricated match reason |
| No platform ratings, TMDB=8/10 | Q=0.8 |
| Neither source has ratings | Q=0.6 |
| Candidate has hidden reviews | Hidden ratings do not change Q or public mean/count |
| Only low-rated watched evidence | H inactive |
| Unrated watched movie | History evidence weight=0.5 |
| One liked anchor, matching actor only | P=0.5 when both people dimensions are available |
| Missing candidate people metadata | Neutral features; no actor/director match claim |
| No positive anchors | P and S inactive |
| Empty metadata across candidates | Finite scores and deterministic ordering |
| Equal rounded scores, different precise scores | Precise score determines order |
| Fully equal scores | Documented identity tie-break applies |

Verify all active values remain in [0,1], final scores remain in [0,100],
and effective weights sum to 1.

## Weather cases

| Case | Expected result |
| --- | --- |
| Rain code with cold temperature | Rainy classification follows precedence |
| Snow code | Snowy classification |
| Clear code with temperature <=10 C | Cold classification |
| Clear code with temperature >=30 C | Hot classification |
| Clear code at mild temperature | Clear classification |
| Overcast/fog at mild temperature | Cloudy classification |
| Unknown code or invalid payload | Weather unavailable; W inactive |
| Timestamp older than 60 minutes | Weather unavailable |
| Timestamp over five minutes ahead | Weather unavailable |
| Weather timeout or rate limit | Useful recommendations continue without W |
| Coordinates omitted | No weather HTTP request |
| Only one coordinate supplied | 422 |
| Same rounded coordinates | Shared weather-cache key |

The ranking-inversion test must hold every non-weather input constant and
change only the weather fixture.

## Candidate and identity cases

| Case | Expected result |
| --- | --- |
| Watched movie outside the 100-row feature window | Still excluded |
| Existing visible or hidden personal review | Candidate excluded |
| Watchlist-only movie | Candidate remains eligible |
| Inactive mapping appears in TMDB results | Candidate excluded |
| Same movie from local/discovery/similar sources | Returned once |
| Matching numeric canonical/TMDB values on different movies | Identities remain distinct |
| Local-only movie | Canonical details route remains usable |
| Unmapped TMDB candidate | local_movie_id=null; no catalog write |
| Administrator metadata override | Stored metadata governs scoring/display |
| Future release | Excluded |
| Missing release date | Eligible without availability claims |
| No eligible movies | 200 with an explicit empty state |

Check that recommendation generation does not write catalog, review,
watched-history, or location records.

## Privacy and authorization cases

- Guest receives 401.
- Incomplete profile receives the established 403 response.
- user_id and custom score/weight/weather parameters receive 422.
- One user's request cannot read another user's private history.
- Hidden review text or moderation metadata is never exposed.
- Successful response uses Cache-Control: private, no-store.
- Coordinates are absent from response bodies and application analytics/logs.
- Sharing preferences do not cause private histories to become public.

## Provider-budget cases

- At most three candidate-source TMDB attempts.
- At most three TMDB detail-enrichment attempts.
- At most six total TMDB attempts and one weather attempt.
- No per-candidate details loop.
- No automatic retries; failures consume budget.
- Warm provider caches avoid corresponding HTTP attempts.
- Concurrent equivalent cache misses are coalesced.
- Deadline prevents scheduling further calls.
- User throttling returns 429 with Retry-After.
- Global provider-budget exhaustion returns cached/local results.
- Missing TMDB configuration does not instantiate a failing provider service.
- Enrichment failure preserves usable candidates with documented metadata
  fallbacks.
- Cached candidates are rechecked against current deactivation, watched,
  review, and moderation state.

## Frontend contract cases

- Preserve backend ranking.
- Display plain-text explanations.
- Keep canonical IDs as strings.
- Keep recommendation, platform, and TMDB scores distinct.
- Render local-only and unmapped TMDB cards.
- Render loading, empty, general, partial, and retry states.
- Location permission denial does not block recommendations.
- Weather failure does not remove useful recommendations.
- Unknown explanation codes remain safe to display.
- An unmounted or superseded request cannot replace newer results.

## Verification record

| Check | Status |
| --- | --- |
| Worked-example arithmetic | Checked during design preparation |
| Backend runtime tests | Not implemented by this design task |
| Frontend runtime tests | Not implemented by this design task |
| Live provider verification | Not performed |
| Backend/frontend contract approval | Pending |