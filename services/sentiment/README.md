
# Sentiment analysis integration — AB#141

Laravel uses **Azure AI Language — Sentiment Analysis** to classify written movie reviews. There is no independently running sentiment microservice in this directory; the external Azure API is called by a Laravel service class and a background job.

- Service class: `backend/app/Services/SentimentAnalysisService.php`
- Queue job: `backend/app/Jobs/AnalyzeReviewSentiment.php`
- Service configuration: `backend/config/services.php` (`azure_language`)
- Automated tests: `backend/tests/Feature/ReviewSentimentTest.php`

## Configuration

Create an Azure AI Language resource (the Free F0 tier can be used for development, subject to availability and limits). From the resource's **Keys and Endpoint** page, copy the endpoint URL and one API key into **`backend/.env`**:

```dotenv
AZURE_LANGUAGE_ENDPOINT=https://<your-resource>.cognitiveservices.azure.com/
AZURE_LANGUAGE_KEY=<your-secret-key>
```

The existing Laravel service configuration reads these values:

```php
'azure_language' => [
    'endpoint' => env('AZURE_LANGUAGE_ENDPOINT'),
    'key' => env('AZURE_LANGUAGE_KEY'),
],
```

Never commit `.env`, include the key in frontend code, or paste it into screenshots, tickets, and pull requests. Replace old `APILAYER_SENTIMENT_*` entries only after confirming no code still depends on them. No APILayer key is required for the Azure implementation.

After changing `.env` or `config/services.php`, clear cached configuration and restart any running queue workers:

```bash
cd backend
php artisan config:clear
php artisan queue:restart
```

## Database and queue worker

Apply the review-sentiment migration (if it has not already run) and start a worker for the dedicated `sentiment` queue:

```bash
cd backend
php artisan migrate
php artisan queue:work database --queue=sentiment --tries=1
```

Use a configured database queue connection and run the worker in a separate terminal. If the worker is not running, written reviews can remain `pending`. The worker uses **one attempt** to conserve quota; the job marks provider failures as `failed` while preserving review text and ratings.

## Provider request and normalization

The Laravel service sends an HTTPS JSON request to:

```text
POST {AZURE_LANGUAGE_ENDPOINT}/language/:analyze-text?api-version=2024-11-01
```

Authentication uses the `Ocp-Apim-Subscription-Key` header. The request sets `kind` to `SentimentAnalysis` and supplies a document containing only the written review text, a fixed request-local ID (`"1"`), and language `en`. It does **not** send star ratings, user IDs, author names, or account information.

The service uses a 5-second connection timeout and a 20-second overall request timeout. It reads the document sentiment from `results.documents[0].sentiment` and confidence values from `results.documents[0].confidenceScores`.

| Azure document label | Application `sentiment_label` | Example derived `sentiment_score` |
| --- | --- | --- |
| `positive` | `positive` | `1.0` when positive = 1 and negative = 0 |
| `negative` | `negative` | `-1.0` when positive = 0 and negative = 1 |
| `neutral` | `neutral` | `0.0` when positive = negative |
| `mixed` | `neutral` | Calculated from the confidences, not necessarily zero |

The application calculates `sentiment_score = positive confidence - negative confidence`, a number between `-1` and `1`. Azure provides the three confidence values, **not** this signed score. If valid positive/negative confidence values are unavailable, the score is `null`.

The application currently treats Azure's `mixed` result as `neutral` to match its three-label API contract. This is a compatibility decision, not a claim that mixed and neutral mean the same thing. For now, the request declares English (`en`); language detection or multilingual handling is outside the current task.

## Review lifecycle and failures

- Creating a written review or changing its text marks sentiment `pending` and queues analysis.
- A completed provider response stores `positive`, `neutral`, or `negative`, the derived score (if available), and status `completed`.
- Rating-only reviews, cleared text, and empty text are `skipped`, with null label and score.
- Editing only the rating does not queue another analysis.
- A text hash prevents an older queued job from overwriting a newer review edit.
- Timeouts, malformed or rejected replies, invalid credentials, quota failures (`429`), and provider outages mark the analysis `failed` but do not reject or undo the review write.

The public review API exposes `sentiment_label`, `sentiment_status`, and nullable `sentiment_score` for frontend display. See [Movie reviews and ratings API](../../docs/api/movie-reviews-contract.md).

## Verification

From `backend/`, run the targeted tests and the full test suite:

```bash
php artisan test --filter=Sentiment
php artisan test
```

`ReviewSentimentTest` mocks Azure responses using `Http::fake()` and prevents real outbound requests. It checks queued processing, positive/negative/neutral persistence, rating-only reviews, edits and clearing text, provider errors/timeouts, stale-job protection, and read/write API safety. These tests **do not** consume Azure quota.

For a minimal **live smoke test**, with valid local credentials and the database queue worker running:

1. Submit one review with non-empty text to the existing review API.
2. Confirm the review is saved immediately with status `pending` (unless a worker has already processed it).
3. Run/observe the sentiment queue worker.
4. Fetch the review again and verify status `completed`, a supported sentiment label, and a derived score when Azure provides valid confidences.
5. If processing fails, inspect Laravel logs and the worker output without exposing the API key.

Do not run automated tests or repeated bulk smoke tests against the live endpoint. The Azure Free F0 tier has usage limits; billing is based on text records, and long inputs can consume more than one record. Monitor the resource in the Azure portal.

## Official provider references

- [Azure AI Language REST API reference](https://learn.microsoft.com/en-us/rest/api/language/)
- [Sentiment Analysis API and confidence scores](https://learn.microsoft.com/en-us/rest/api/language/analyze-text/analyze-text/analyze-text?view=rest-language-analyze-text-2024-11-01)
- [Azure AI Language service data limits](https://learn.microsoft.com/en-us/azure/ai-services/language-service/concepts/data-limits)

## Review UI integration — AB#142

Movie Details and public-profile review cards display the persisted
classification. UI refreshes read stored results and never enqueue analysis.

The job timeout is 30 seconds, exceeding the service's 20-second HTTP timeout.
Keep queue retry_after greater than 30 seconds. Worker-recognized job failures
mark only the current pending text revision failed.

Classification writes match the pending revision in the database update.

See [sentiment display and verification](../../docs/frontend/review-sentiment.md).