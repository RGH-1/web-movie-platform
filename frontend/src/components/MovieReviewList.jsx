import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { getMovieReviews } from '../services/reviews.js';
import { getReviewSentiment } from '../utils/reviewSentiment.js';
import MoviePagination from './MoviePagination.jsx';
import ReviewSentiment from './ReviewSentiment.jsx';

const POLL_INTERVAL_MS = 5000;
const MAX_BACKGROUND_REFRESHES = 6;

const formatReviewDate = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function ReviewCard({ review }) {
    return (
        <article className="border rounded p-3">
            <div className="d-flex flex-wrap justify-content-between gap-2 mb-2">
                {review.author ? (
                    <Link to={`/users/${encodeURIComponent(review.author.id)}`}>
                        {review.author.name}
                    </Link>
                ) : (
                    <span>Former user</span>
                )}

                <strong aria-label={`User rating: ${review.rating} out of 5`}>
                    {review.rating} / 5
                </strong>
            </div>

            {review.created_at && (
                <time
                    dateTime={review.created_at}
                    className="small text-body-secondary"
                >
                    {formatReviewDate.format(new Date(review.created_at))}
                </time>
            )}

            {review.review_text ? (
                <p
                    className="mt-2 mb-0 text-break"
                    style={{ whiteSpace: 'pre-wrap' }}
                >
                    {review.review_text}
                </p>
            ) : (
                <p className="mt-2 mb-0 text-body-secondary">Rating only</p>
            )}

            <ReviewSentiment review={review} />
        </article>
    );
}

export default function MovieReviewList({ movieId, unavailable }) {
    const headingId = useId();
    const [query, setQuery] = useState({ page: 1 });
    const [result, setResult] = useState(null);

    useEffect(() => {
        if (!movieId || unavailable) return;

        const controller = new AbortController();
        let timer;
        let backgroundRefreshes = 0;

        async function load(background = false) {
            if (controller.signal.aborted) return;

            if (background) {
                setResult((previous) => (
                    previous?.movieId === movieId &&
                        previous.query === query
                        ? { ...previous, refreshing: true }
                        : previous
                ));
            }

            try {
                const data = await getMovieReviews(
                    movieId,
                    query.page,
                    { signal: controller.signal },
                );

                if (controller.signal.aborted) return;

                setResult({ movieId, query, data });

                const hasPending = data.reviews.some(
                    (review) => getReviewSentiment(review).state === 'pending',
                );

                if (
                    hasPending &&
                    backgroundRefreshes < MAX_BACKGROUND_REFRESHES
                ) {
                    timer = window.setTimeout(() => {
                        if (
                            controller.signal.aborted ||
                            document.visibilityState === 'hidden'
                        ) {
                            return;
                        }

                        backgroundRefreshes += 1;
                        void load(true);
                    }, POLL_INTERVAL_MS);
                }
            } catch (error) {
                if (controller.signal.aborted) return;

                setResult((previous) => {
                    const canKeepPrevious =
                        error.response?.status !== 404 &&
                        previous?.movieId === movieId &&
                        previous.query.page === query.page &&
                        previous.data;

                    return canKeepPrevious
                        ? {
                            movieId,
                            query,
                            data: previous.data,
                            refreshError: true,
                        }
                        : { movieId, query, error: true };
                });
            }
        }

        void load();

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [movieId, unavailable, query]);

    const current =
        result?.movieId === movieId &&
            result.query.page === query.page
            ? result
            : null;

    const refreshing = Boolean(
        current?.data &&
        (current.query !== query || current.refreshing),
    );

    let content;

    if (unavailable) {
        content = <p className="mb-0">Reviews are unavailable for this movie.</p>;
    } else if (!movieId) {
        content = <p className="mb-0">No platform ratings yet.</p>;
    } else if (!current) {
        content = <p role="status" className="mb-0">Loading reviews.</p>;
    } else if (current.error) {
        content = (
            <>
                <p role="alert">Could not load reviews.</p>
                <button
                    type="button"
                    className="btn btn-outline-primary"
                    onClick={() => setQuery({ page: query.page })}
                >
                    Retry reviews
                </button>
            </>
        );
    } else {
        const { reviews, meta } = current.data;
        const { rating_average: average, rating_count: count } = meta;
        const hasPending = reviews.some(
            (review) => getReviewSentiment(review).state === 'pending',
        );

        content = (
            <>
                <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <p className="mb-0">
                        <strong>Platform rating:</strong>{' '}
                        {average === null ? 'No ratings yet' : `${average} / 5`}
                        {' '}({count} {count === 1 ? 'rating' : 'ratings'})
                    </p>

                    <button
                        type="button"
                        className="btn btn-sm btn-outline-primary"
                        disabled={refreshing}
                        onClick={() => setQuery({ page: query.page })}
                    >
                        Refresh reviews
                    </button>
                </div>

                <p className="small text-body-secondary">
                    Text sentiment is automated analysis of the written review,
                    separate from the user&apos;s star rating.
                </p>

                {refreshing && <p role="status">Refreshing reviews.</p>}

                {current.refreshError && (
                    <p role="alert" className="text-danger">
                        Could not refresh reviews. Showing the last loaded reviews.
                    </p>
                )}

                {hasPending && (
                    <p className="small text-body-secondary">
                        Pending labels are checked automatically for a limited
                        time. You can also refresh reviews.
                    </p>
                )}

                {reviews.length ? (
                    <div className="d-grid gap-3">
                        {reviews.map((review) => (
                            <ReviewCard key={review.id} review={review} />
                        ))}
                    </div>
                ) : (
                    <p className="mb-0">
                        {count === 0
                            ? 'No reviews yet.'
                            : 'No reviews on this page.'}
                    </p>
                )}

                {meta.last_page > 1 && (
                    <MoviePagination
                        label="Reviews pagination"
                        page={meta.current_page}
                        totalPages={meta.last_page}
                        loading={refreshing}
                        onPageChange={(page) => setQuery({ page })}
                    />
                )}
            </>
        );
    }

    return (
        <section className="card mt-4" aria-labelledby={headingId}>
            <div className="card-body p-3 p-md-4">
                <h2 id={headingId} className="h4 mb-3">Movie reviews</h2>
                {content}
            </div>
        </section>
    );
}