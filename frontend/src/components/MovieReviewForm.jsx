import { useEffect, useId, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { saveMovieReview } from '../services/reviews.js';

const RATINGS = [1, 2, 3, 4, 5];
const REVIEW_MAX_LENGTH = 2000;

function readSaveError(error) {
    const { status, data } = error.response ?? {};

    if (status === 422) {
        return {
            message: 'Check the review fields and try again.',
            rating: data?.errors?.rating?.[0],
            reviewText: data?.errors?.review_text?.[0],
        };
    }

    if (status === 401) {
        return { message: 'Sign in again to save your review.', action: 'login' };
    }

    if (status === 403 && data?.code === 'PROFILE_INCOMPLETE') {
        return { message: 'Complete your profile to save a review.', action: 'profile' };
    }

    if (status === 403) {
        return { message: 'You do not have permission to change this review.' };
    }

    if (status === 409 && data?.code === 'REVIEW_ALREADY_EXISTS') {
        return {
            message: 'You already have a review for this movie. Reload to edit it.',
            action: 'reload',
        };
    }

    if (status === 404) {
        return {
            message: 'This movie or review is no longer available. Reload to check its status.',
            action: 'reload',
        };
    }

    if (status === 419) {
        return { message: 'Your session could not be verified. Try saving again.' };
    }

    return { message: 'Unable to save your review. Try again.' };
}

export default function MovieReviewForm({ movieId, existingReview, onReload }) {
    const location = useLocation();
    const id = useId();
    const pending = useRef(null);
    const [reviewId, setReviewId] = useState(existingReview?.id ?? null);
    const [rating, setRating] = useState(String(existingReview?.rating ?? ''));
    const [reviewText, setReviewText] = useState(existingReview?.review_text ?? '');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState('');

    useEffect(() => () => pending.current?.abort(), []);

    async function handleSubmit(event) {
        event.preventDefault();
        if (pending.current || error?.action === 'reload') return;

        setSuccess('');
        setError(null);

        const selectedRating = Number(rating);
        const text = reviewText.trim();
        const errors = {};

        if (!RATINGS.includes(selectedRating)) {
            errors.rating = 'Choose a rating from 1 to 5.';
        }

        if (Array.from(text).length > REVIEW_MAX_LENGTH) {
            errors.reviewText = 'Use 2,000 characters or fewer.';
        }

        if (errors.rating || errors.reviewText) {
            setError(errors);
            return;
        }

        const controller = new AbortController();
        pending.current = controller;
        setSaving(true);

        try {
            const saved = await saveMovieReview({
                movieId,
                reviewId,
                rating: selectedRating,
                reviewText: text || null,
                signal: controller.signal,
            });

            if (!controller.signal.aborted) {
                setReviewId(saved.id);
                setRating(String(saved.rating));
                setReviewText(saved.review_text ?? '');
                setSuccess(reviewId === null ? 'Review submitted.' : 'Review updated.');
            }
        } catch (saveError) {
            if (!controller.signal.aborted) {
                setError(readSaveError(saveError));
            }
        } finally {
            pending.current = null;
            if (!controller.signal.aborted) setSaving(false);
        }
    }

    return (
        <form
            aria-label="Movie review"
            aria-busy={saving}
            noValidate
            onSubmit={handleSubmit}
        >
            {error?.message && (
                <div className="alert alert-danger" role="alert">
                    <p className={error.action ? 'mb-2' : 'mb-0'}>{error.message}</p>
                    {error.action === 'login' && (
                        <Link to="/login" state={{ from: location }}>Sign in</Link>
                    )}
                    {error.action === 'profile' && (
                        <Link to="/profile?edit=1">Complete profile</Link>
                    )}
                    {error.action === 'reload' && (
                        <button type="button" className="btn btn-outline-dark" onClick={onReload}>
                            Reload movie details
                        </button>
                    )}
                </div>
            )}

            <fieldset disabled={saving}>
                <div className="mb-3">
                    <label htmlFor={`${id}-rating`} className="form-label">Rating</label>
                    <select
                        id={`${id}-rating`}
                        className="form-select"
                        required
                        value={rating}
                        onChange={(event) => setRating(event.target.value)}
                        aria-invalid={Boolean(error?.rating)}
                        aria-describedby={error?.rating ? `${id}-rating-error` : undefined}
                    >
                        <option value="">Choose a rating</option>
                        {RATINGS.map((value) => (
                            <option key={value} value={value}>{value} out of 5</option>
                        ))}
                    </select>
                    {error?.rating && (
                        <p id={`${id}-rating-error`} className="text-danger small mt-1" role="alert">
                            {error.rating}
                        </p>
                    )}
                </div>

                <div className="mb-3">
                    <label htmlFor={`${id}-text`} className="form-label">Review (optional)</label>
                    <textarea
                        id={`${id}-text`}
                        className="form-control"
                        rows={4}
                        value={reviewText}
                        onChange={(event) => setReviewText(event.target.value)}
                        aria-invalid={Boolean(error?.reviewText)}
                        aria-describedby={error?.reviewText ? `${id}-text-error` : `${id}-text-help`}
                    />
                    <div id={`${id}-text-help`} className="form-text">Up to 2,000 characters.</div>
                    {error?.reviewText && (
                        <p id={`${id}-text-error`} className="text-danger small mt-1" role="alert">
                            {error.reviewText}
                        </p>
                    )}
                </div>

                <div className="d-grid d-sm-flex">
                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={error?.action === 'reload'}
                    >
                        {saving ? 'Saving…' : reviewId === null ? 'Submit review' : 'Save changes'}
                    </button>
                </div>
            </fieldset>

            <div role="status" className="text-success mt-2">
                {saving ? 'Saving your review…' : success}
            </div>
        </form>
    );
}