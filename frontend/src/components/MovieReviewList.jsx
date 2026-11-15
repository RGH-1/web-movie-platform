import { useEffect, useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { getMovieReviews } from '../services/reviews.js';
import MoviePagination from './MoviePagination.jsx';

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
                <strong>{review.rating} / 5</strong>
            </div>

            {review.created_at && (
                <time dateTime={review.created_at} className="small text-body-secondary">
                    {formatReviewDate.format(new Date(review.created_at))}
                </time>
            )}

            {review.review_text ? (
                <p className="mt-2 mb-0 text-break" style={{ whiteSpace: 'pre-wrap' }}>
                    {review.review_text}
                </p>
            ) : (
                <p className="mt-2 mb-0 text-body-secondary">Rating only</p>
            )}
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

        getMovieReviews(movieId, query.page, { signal: controller.signal })
            .then((data) => {
                if (!controller.signal.aborted) setResult({ query, data });
            })
            .catch(() => {
                if (!controller.signal.aborted) setResult({ query, error: true });
            });

        return () => controller.abort();
    }, [movieId, unavailable, query]);

    const current = result?.query === query ? result : null;
    let content;

    if (unavailable) {
        content = <p className="mb-0">Reviews are unavailable for this movie.</p>;
    } else if (!movieId) {
        content = <p className="mb-0">No platform ratings yet.</p>;
    } else if (!current) {
        content = <p role="status" className="mb-0">Loading reviews…</p>;
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

        content = (
            <>
                <p className="mb-3">
                    <strong>Platform rating:</strong>{' '}
                    {average === null ? 'No ratings yet' : `${average} / 5`}
                    {' '}({count} {count === 1 ? 'rating' : 'ratings'})
                </p>

                {reviews.length ? (
                    <div className="d-grid gap-3">
                        {reviews.map((review) => (
                            <ReviewCard key={review.id} review={review} />
                        ))}
                    </div>
                ) : (
                    <p className="mb-0">
                        {count === 0 ? 'No reviews yet.' : 'No reviews on this page.'}
                    </p>
                )}

                {meta.last_page > 1 && (
                    <MoviePagination
                        label="Reviews pagination"
                        page={meta.current_page}
                        totalPages={meta.last_page}
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