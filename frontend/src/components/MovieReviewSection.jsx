import { Link, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth.js';
import MovieReviewForm from './MovieReviewForm.jsx';

export default function MovieReviewSection({ movie, onReload }) {
    const { user, loading, profileCompletion } = useAuth();
    const location = useLocation();
    const movieId = movie.local_movie_id;

    let content;
    if (loading) {
        content = <p role="status" className="mb-0">Checking authentication…</p>;
    } else if (!user) {
        content = (
            <Link to="/login" state={{ from: location }}>Sign in to leave a review</Link>
        );
    } else if (profileCompletion?.complete === false) {
        content = <Link to="/profile?edit=1">Complete your profile to leave a review</Link>;
    } else if (typeof movieId !== 'string' || !/^[1-9]\d*$/.test(movieId)) {
        content = <p className="mb-0">Reviews are unavailable for this movie.</p>;
    } else {
        content = (
            <MovieReviewForm
                key={`${user.id}:${movieId}`}
                movieId={movieId}
                existingReview={movie.viewer_review}
                onReload={onReload}
            />
        );
    }

    return (
        <section className="card mt-4">
            <div className="card-body p-3 p-md-4">
                <h2 className="h4 mb-3">Your rating and review</h2>
                {content}
            </div>
        </section>
    );
}