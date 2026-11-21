# Review sentiment display and verification — AB#142

## Scope

Display server-produced sentiment on Movie Details review cards and
public-profile review cards.

The existing review API and public-profile responses provide the sentiment
fields. No new endpoint or frontend provider integration is required.

## Display rules

| API/content state | Display |
| --- | --- |
| completed + positive | Text sentiment: Positive |
| completed + neutral | Text sentiment: Neutral |
| completed + negative | Text sentiment: Negative |
| pending + written text | Text sentiment: Pending |
| Failed, unknown, or inconsistent state + written text | Text sentiment: Unavailable |
| Missing or blank written text | Text sentiment: Not applicable |

Empty content takes precedence over any stale classification fields.

The UI never infers sentiment from the star rating or sentiment_score.
Unavailable is not a completed Neutral classification.

Labels include text and do not rely on color alone.

Sentiment describes automated analysis of written content. Star ratings
remain the user's independent 1–5 rating.

## Refresh behavior

Movie Details:

- Fetch the current public review page.
- While written reviews on that page remain pending, perform at most six
  background refreshes, spaced five seconds after successful responses.
- Stop when no written review remains pending, a request fails, the tab is
  hidden, the component unmounts, or the page/movie changes.
- Refresh reviews restarts a bounded check cycle for the current page.
- Preserve loaded cards after recoverable refresh failures.
- A 404 does not retain stale review data.
- Abort superseded requests and clear scheduled timers.

Public profiles:

- Use the shared sentiment component.
- Refresh public reviews reloads the existing public-profile endpoint.
- Preserve the last loaded profile after recoverable refresh failures.
- Follow controls are disabled while profile refresh is in progress.

Refreshing labels does not enqueue analysis, call Azure, reload Movie
Details, or reset an active review edit draft.

## Backend integration correction

AnalyzeReviewSentiment uses a 30-second job timeout around the existing
20-second HTTP request timeout.

The job requests failure on worker-recognized timeout and its failed
callback marks the current pending revision failed.

Success/failure writes conditionally match review ID, pending status, and
text hash. An older revision cannot overwrite a newer classification.

Keep the queue connection retry_after greater than the job timeout.
The committed database connection defaults to 90 seconds.

HTTP timeout handling and worker failure handling are separate paths.
The automated failure-callback test simulates the callback rather than
terminating a real worker process.

## Automated coverage

Existing S1 coverage:

- ReviewSentimentTest

Additional API/job/read coverage:

- ReviewSentimentIntegrationTest

Frontend coverage:

- reviewSentiment.test.jsx
- Existing movieReviewsPanel.test.jsx
- Existing movieReviewForm.test.jsx
- Existing publicProfile.test.jsx

Coverage includes valid labels, unknown/failed/pending states, empty text,
reclassification, rating-only edits, CRUD compatibility, bounded polling,
cleanup, hidden-tab behavior, refresh failure/retry, profile refresh, and
edit-draft preservation.

Backend tests use fake provider responses and prevent stray HTTP requests.
Frontend tests use the shared Axios client's test adapter.

## Manual verification

Use the documented local movie fixture and fake-provider Tinker helper to
verify the browser/API/job-handler flow without live provider requests.

Separately record whether actual queue delivery and live Azure
classification were observed.

## Results

Record actual output and the tested commit before closing AB#142.

| Check | Result |
| --- | --- |
| Focused sentiment backend tests | Pass |
| Review/moderation/profile backend regressions | Pass |
| Full backend suite and skip reasons | Pass |
| Focused sentiment/review/profile UI tests | Pass |
| Full frontend tests | Pass |
| Frontend lint and build | Pass |
| Controlled browser/API/job-handler verification | Pass |
| Draft preservation and offline retry | Pending |
| Responsive layout | Pass |
| Actual queue worker/live Azure smoke check | Not performed |

Do not convert pending entries into passing results without observed output
or an explicitly reported result.