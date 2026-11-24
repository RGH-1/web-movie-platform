import { useEffect, useRef, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import AdminNavigation from '../components/AdminNavigation';
import MoviePagination from '../components/MoviePagination';
import { getAdminReviews, moderateReview } from '../services/adminReviews';

export default function AdminReviewsPage() {
    const location = useLocation();
    const [request, setRequest] = useState({ page: 1, status: '' });
    const [result, setResult] = useState(null);
    const [selected, setSelected] = useState(null);
    const [reason, setReason] = useState('');
    const [fieldError, setFieldError] = useState('');
    const [actionError, setActionError] = useState('');
    const [notice, setNotice] = useState('');
    const [saving, setSaving] = useState(false);
    const mutation = useRef(null);

    useEffect(() => () => mutation.current?.abort(), []);

    useEffect(() => {
        const controller = new AbortController();
        getAdminReviews(request, { signal: controller.signal }).then((response) => {
            if (controller.signal.aborted) return;
            if (request.page > response.meta.last_page) {
                setRequest({ ...request, page: response.meta.last_page });
                return;
            }
            setResult({ request, response });
        }).catch((error) => {
            if (!controller.signal.aborted) setResult({ request, error });
        });
        return () => controller.abort();
    }, [request]);

    function startModeration(review) {
        setSelected(review);
        setReason('');
        setFieldError('');
        setActionError('');
        setNotice('');
    }

    async function confirmModeration(event) {
        event.preventDefault();
        if (!selected || mutation.current) return;

        const text = reason.trim();
        setActionError('');
        if (!text || Array.from(text).length > 1000) {
            setFieldError('Enter a reason of 1 to 1000 characters.');
            return;
        }

        const controller = new AbortController();
        mutation.current = controller;
        setSaving(true);
        setFieldError('');
        try {
            const status = selected.moderation.status === 'hidden' ? 'visible' : 'hidden';
            const updated = await moderateReview(selected.id, { status, reason: text }, { signal: controller.signal });
            if (controller.signal.aborted) return;
            setSelected(null);
            setNotice(updated.moderation.status === 'hidden' ? 'Review hidden.' : 'Review restored.');
            setRequest({ ...request });
        } catch (error) {
            if (controller.signal.aborted) return;
            const status = error.response?.status;
            if (status === 401 || status === 403) {
                setSelected(null);
                setResult({ request, error });
            } else if (status === 422 && error.response.data?.errors?.reason?.length) {
                setFieldError(error.response.data.errors.reason.join(' '));
            } else if (status === 404) {
                setActionError('This review is no longer available. Close the form and refresh the results.');
            } else {
                setActionError('Could not update the review. Please try again.');
            }
        } finally {
            if (!controller.signal.aborted) {
                mutation.current = null;
                setSaving(false);
            }
        }
    }

    const current = result?.request === request ? result : null;
    const status = current?.error?.response?.status;
    const response = current?.response;
    if (status === 401) return <Navigate to="/login" replace state={{ from: location }} />;

    return (
        <main className="container py-4 py-md-5 text-start">
            <AdminNavigation />
            <h1 className="h2 mt-3">Review moderation</h1>
            {status !== 403 && (
                <div className="d-flex flex-wrap align-items-end gap-3 mb-4">
                    <div>
                        <label className="form-label" htmlFor="review-status-filter">Review status</label>
                        <select id="review-status-filter" className="form-select" value={request.status} disabled={!!selected}
                            onChange={(event) => {
                                setNotice('');
                                setRequest({ page: 1, status: event.target.value });
                            }}>
                            <option value="">All reviews</option>
                            <option value="visible">Visible</option>
                            <option value="hidden">Hidden</option>
                        </select>
                    </div>
                    {response && <button type="button" className="btn btn-outline-secondary" disabled={!!selected}
                        onClick={() => setRequest({ ...request })}>Refresh reviews</button>}
                </div>
            )}

            {!current && <p role="status">Loading reviews…</p>}
            {current?.error && (
                <div role="alert" className="alert alert-danger">
                    {status === 403 ? 'Administrator access is required.' : 'Could not load reviews.'}
                    {status !== 403 && <button type="button" className="btn btn-outline-danger btn-sm d-block mt-2"
                        onClick={() => setRequest({ ...request })}>Try again</button>}
                </div>
            )}
            {notice && <p role="status" className="alert alert-success">{notice}</p>}
            {response?.data.length === 0 && <p role="status">No reviews match this status.</p>}

            {response?.data.length > 0 && (
                <div className="d-grid gap-3">
                    {response.data.map((review) => {
                        const { author, movie, moderation } = review;
                        const hidden = moderation.status === 'hidden';
                        const confirming = selected?.id === review.id;
                        const title = movie?.title ?? 'Movie unavailable';
                        return (
                            <article key={review.id} className="card">
                                <div className="card-body">
                                    <header className="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                        <div>
                                            <h2 className="h5 text-break mb-1">
                                                {movie?.is_active ? <Link to={`/movies/local/${movie.local_movie_id}`}>{title}</Link> : title}
                                            </h2>
                                            <p className="text-break mb-2">
                                                By {author ? <Link to={`/users/${author.id}`}>{author.name}</Link> : 'Unknown author'}
                                                {' · '}{review.rating} / 5
                                            </p>
                                        </div>
                                        <span className={`badge ${hidden ? 'text-bg-secondary' : 'text-bg-success'}`}>{hidden ? 'Hidden' : 'Visible'}</span>
                                    </header>
                                    {review.review_text?.trim() ? (
                                        <details className="mb-2">
                                            <summary>Read review</summary>
                                            <p className="text-break mt-2 mb-0" style={{ whiteSpace: 'pre-wrap' }}>{review.review_text}</p>
                                        </details>
                                    ) : <p className="small text-body-secondary mb-2">Rating only</p>}
                                    {moderation.moderated_at && (
                                        <div className="small text-body-secondary mb-2">
                                            <p className="mb-0 text-break">
                                                Last moderated by {moderation.moderator?.name ?? 'Former moderator'} on{' '}
                                                <time dateTime={moderation.moderated_at}>{new Date(moderation.moderated_at).toLocaleString()}</time>
                                            </p>
                                            {moderation.reason && <p className="mb-0 text-break" style={{ whiteSpace: 'pre-wrap' }}>Reason: {moderation.reason}</p>}
                                        </div>
                                    )}
                                    {confirming ? (
                                        <form className="border rounded p-3 mt-3" noValidate onSubmit={confirmModeration}>
                                            <h3 className="h6">{hidden ? 'Restore this review to public pages?' : 'Hide this review from public pages?'}</h3>
                                            <fieldset disabled={saving}>
                                                <label className="form-label" htmlFor="moderation-reason">Moderation reason</label>
                                                <textarea id="moderation-reason" className="form-control mb-2" rows={3}
                                                    required value={reason} onChange={(event) => setReason(event.target.value)}
                                                    aria-invalid={!!fieldError} aria-describedby={fieldError ? 'moderation-reason-error' : undefined} />
                                                {fieldError && <p id="moderation-reason-error" className="small text-danger" role="alert">{fieldError}</p>}
                                                {actionError && <p className="text-danger" role="alert">{actionError}</p>}
                                                <div className="d-flex flex-wrap gap-2">
                                                    <button type="submit" className={`btn ${hidden ? 'btn-primary' : 'btn-danger'}`}>
                                                        {saving ? 'Saving…' : hidden ? 'Confirm restore' : 'Confirm hide'}
                                                    </button>
                                                    <button type="button" className="btn btn-outline-secondary" onClick={() => setSelected(null)}>Cancel</button>
                                                </div>
                                            </fieldset>
                                        </form>
                                    ) : (
                                        <button type="button" className={`btn btn-sm ${hidden ? 'btn-outline-primary' : 'btn-outline-danger'}`}
                                            aria-label={`${hidden ? 'Restore' : 'Hide'} review of ${title}${author ? ` by ${author.name}` : ''}`}
                                            disabled={!!selected} onClick={() => startModeration(review)}>
                                            {hidden ? 'Restore review' : 'Hide review'}
                                        </button>
                                    )}
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
            {response?.meta.last_page > 1 && (
                <MoviePagination page={response.meta.current_page} totalPages={response.meta.last_page}
                    label="Review moderation pagination" loading={!!selected}
                    onPageChange={(page) => { setNotice(''); setRequest({ ...request, page }); }} />
            )}
        </main>
    );
}
