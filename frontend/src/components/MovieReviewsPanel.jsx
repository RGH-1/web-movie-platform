import { useEffect, useRef, useState } from 'react';
import { useAuth } from '../hooks/useAuth.js';
import { deleteMovieReview } from '../services/reviews.js';
import MovieReviewList from './MovieReviewList.jsx';
import MovieReviewSection from './MovieReviewSection.jsx';

export default function MovieReviewsPanel({ movie, onReload }) {
    const { user, loading, profileCompletion } = useAuth();
    const canManage = !loading && Boolean(user) && profileCompletion?.complete !== false;
    const identity = movie.tmdb_id ?? movie.local_movie_id;

    return (
        <MovieReviews
            key={`${identity}:${user?.id ?? 'guest'}`}
            movie={movie}
            canManage={canManage}
            onReload={onReload}
        />
    );
}

function MovieReviews({ movie, canManage, onReload }) {
    const [movieId, setMovieId] = useState(movie.local_movie_id ?? null);
    const [viewerReview, setViewerReview] = useState(movie.viewer_review ?? null);
    const [editing, setEditing] = useState(false);
    const [revision, setRevision] = useState(0);
    const [deleting, setDeleting] = useState(false);
    const [notice, setNotice] = useState('');
    const [deleteError, setDeleteError] = useState('');
    const pendingDelete = useRef(null);

    useEffect(() => () => pendingDelete.current?.abort(), []);

    const unavailable = movie.catalog_deactivated === true;
    const ownReview = canManage && !unavailable ? viewerReview : null;

    function handleSaved(review, savedMovieId) {
        setMovieId(savedMovieId);
        setViewerReview(review);
        setEditing(false);
        setNotice(viewerReview ? 'Review updated.' : 'Review submitted.');
        setRevision((value) => value + 1);
    }

    async function handleDelete() {
        if (!ownReview || pendingDelete.current) return;
        if (!window.confirm('Delete your rating and review?')) return;

        const controller = new AbortController();
        pendingDelete.current = controller;
        setDeleting(true);
        setNotice('');
        setDeleteError('');

        try {
            await deleteMovieReview(ownReview.id, { signal: controller.signal });

            if (!controller.signal.aborted) {
                setViewerReview(null);
                setNotice('Review deleted.');
                setRevision((value) => value + 1);
            }
        } catch (error) {
            if (controller.signal.aborted) return;

            if (error.response?.status === 404) {
                setViewerReview(null);
                setNotice('This review is no longer available.');
                setRevision((value) => value + 1);
            } else {
                setDeleteError('Could not delete your review. Please try again.');
            }
        } finally {
            pendingDelete.current = null;
            if (!controller.signal.aborted) setDeleting(false);
        }
    }

    return (
        <>
            <MovieReviewList
                key={`${movieId}:${revision}`}
                movieId={movieId}
                unavailable={unavailable}
            />

            {notice && <p role="status" className="text-success mt-3">{notice}</p>}
            {deleteError && <p role="alert" className="text-danger mt-3">{deleteError}</p>}

            {ownReview && !editing ? (
                <section className="card mt-4" aria-label="Your rating and review">
                    <div className="card-body p-3 p-md-4">
                        <h2 className="h4 mb-3">Your rating and review</h2>
                        <p className="mb-3">{ownReview.rating} / 5</p>
                        <div className="d-flex flex-wrap gap-2">
                            <button
                                type="button"
                                className="btn btn-outline-primary"
                                disabled={deleting}
                                onClick={() => {
                                    setNotice('');
                                    setDeleteError('');
                                    setEditing(true);
                                }}
                            >
                                Edit review
                            </button>
                            <button
                                type="button"
                                className="btn btn-outline-danger"
                                disabled={deleting}
                                onClick={handleDelete}
                            >
                                {deleting ? 'Deleting…' : 'Delete review'}
                            </button>
                        </div>
                    </div>
                </section>
            ) : (
                <MovieReviewSection
                    movie={{ ...movie, local_movie_id: movieId, viewer_review: ownReview }}
                    onReload={onReload}
                    onSaved={handleSaved}
                    onCancel={editing ? () => setEditing(false) : undefined}
                />
            )}
        </>
    );
}