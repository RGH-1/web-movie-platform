import { useEffect, useState } from "react";
import { Link, Navigate, useLocation, useParams } from "react-router-dom";

import { getMovieDetails } from "../services/movies";
import MovieDetailsPage from "./MovieDetailsPage";
import MovieReviewSection from "../components/MovieReviewSection.jsx";

function MovieDetailNavigation() {
    const { tmdbId } = useParams();
    const location = useLocation();
    const movieId = Number(tmdbId);
    const isValidId =
        /^\d+$/.test(tmdbId || "") &&
        Number.isSafeInteger(movieId) &&
        movieId > 0 &&
        movieId <= 2147483647;

    const [attempt, setAttempt] = useState(0);
    const [now, setNow] = useState(Date.now);
    const [result, setResult] = useState({
        tmdbId: null,
        attempt: null,
        movie: null,
        error: null,
    });

    useEffect(() => {
        if (!isValidId) return;
        let isCurrent = true;

        getMovieDetails(movieId)
            .then((movie) => {
                if (!isCurrent) return;
                setResult({ tmdbId, attempt, movie, error: null });
            })
            .catch((error) => {
                if (!isCurrent) return;
                const status = error.response?.status;
                let message = "Unable to load movie details. Please try again.";

                if (status === 404) {
                    message = "Movie not found.";
                } else if (status === 422) {
                    message = "Invalid movie identifier.";
                } else if (status === 403) {
                    message = "Please complete your profile to view movie details.";
                } else if (status === 502 || status === 503) {
                    message = "Movie information is temporarily unavailable.";
                }

                // Laravel's rate-limit contract returns a positive delay in seconds.
                const seconds = Number(error.response?.headers?.["retry-after"]);
                const retryAt = status === 503
                    ? Date.now() + (Number.isSafeInteger(seconds) && seconds > 0 ? seconds : 30) * 1000
                    : null;

                setResult({
                    tmdbId, attempt, movie: null, error: message, status, retryAt,
                });
            });

        return () => { isCurrent = false; };
    }, [tmdbId, movieId, isValidId, attempt]);

    useEffect(() => {
        if (!result.retryAt) return;
        const timer = setTimeout(() => setNow(Date.now()), Math.max(0, result.retryAt - Date.now()));
        return () => clearTimeout(timer);
    }, [result.retryAt]);

    const backLink = (
        <Link to="/movies" className="btn btn-outline-primary">
            Back to Movie Discovery
        </Link>
    );

    if (!isValidId) {
        return (
            <main className="container py-5">
                <div className="alert alert-warning" role="alert">Invalid movie identifier.</div>
                {backLink}
            </main>
        );
    }

    if (result.tmdbId !== tmdbId || result.attempt !== attempt) {
        return (
            <main className="container py-5">
                <p role="status">Loading movie details...</p>
            </main>
        );
    }

    if (result.status === 401) {
        return <Navigate to="/login" replace state={{ from: location }} />;
    }

    if (result.error) {
        const retryable = ![401, 403, 404, 422].includes(result.status);
        const waiting = result.retryAt != null && now < result.retryAt;

        return (
            <main className="container py-5">
                <div className="alert alert-danger" role="alert">{result.error}</div>
                {waiting && (
                    <p role="status" className="mb-3">
                        Please wait before retrying. The retry button will become available automatically.
                    </p>
                )}
                <div className="d-flex flex-wrap justify-content-center gap-2">
                    {retryable && (
                        <button type="button" className="btn btn-primary" disabled={waiting}
                            onClick={() => setAttempt((value) => value + 1)}>
                            Try again
                        </button>
                    )}
                    {result.status === 403 && (
                        <Link to="/profile?edit=1" className="btn btn-primary">Complete profile</Link>
                    )}
                    {backLink}
                </div>
            </main>
        );
    }

    return (
        <>
            <div className="container pt-4">{backLink}</div>
            <MovieDetailsPage movie={result.movie}>
                {Object.hasOwn(result.movie, "local_movie_id") && (
                    <MovieReviewSection
                        movie={result.movie}
                        onReload={() => setAttempt((value) => value + 1)}
                    />
                )}
            </MovieDetailsPage>
        </>
    );
}

export default MovieDetailNavigation;
