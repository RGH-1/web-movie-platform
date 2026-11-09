import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";

import { getMovieDetails } from "../services/movies";
import MovieDetailsPage from "./MovieDetailsPage";

function MovieDetailNavigation() {
    const { tmdbId } = useParams();

    const movieId = Number(tmdbId);

    const isValidId =
        /^\d+$/.test(tmdbId || "") &&
        Number.isSafeInteger(movieId) &&
        movieId > 0 &&
        movieId <= 2147483647;

    const [result, setResult] = useState({
        tmdbId: null,
        movie: null,
        loading: true,
        error: null,
    });

    useEffect(() => {
        if (!isValidId) return;

        let isCurrent = true;

        getMovieDetails(movieId)
            .then((movie) => {
                if (!isCurrent) return;

                setResult({
                    tmdbId,
                    movie,
                    loading: false,
                    error: null,
                });
            })
            .catch((error) => {
                if (!isCurrent) return;

                const status = error.response?.status;
                let message = "Unable to load movie details. Please try again.";

                if (status === 404) {
                    message = "Movie not found.";
                } else if (status === 403) {
                    message =
                        "Please complete your profile to view movie details.";
                } else if (status === 502 || status === 503) {
                    message = "Movie information is temporarily unavailable.";
                }

                setResult({
                    tmdbId,
                    movie: null,
                    loading: false,
                    error: message,
                });
            });

        return () => {
            isCurrent = false;
        };
    }, [tmdbId, movieId, isValidId]);

    if (!isValidId) {
        return (
            <main className="container py-5">
                <div className="alert alert-warning" role="alert">
                    Invalid movie identifier.
                </div>
                <Link to="/movies" className="btn btn-outline-primary">
                    Back to Movie Discovery
                </Link>
            </main>
        );
    }

    if (result.loading || result.tmdbId !== tmdbId) {
        return (
            <main className="container py-5">
                <p role="status">Loading movie details...</p>
            </main>
        );
    }

    if (result.error) {
        return (
            <main className="container py-5">
                <div className="alert alert-danger" role="alert">
                    {result.error}
                </div>
                <Link to="/movies" className="btn btn-outline-primary">
                    Back to Movie Discovery
                </Link>
            </main>
        );
    }

    return (
        <>
            <div className="container pt-4">
                <Link to="/movies" className="btn btn-outline-primary">
                    Back to Movie Discovery
                </Link>
            </div>

            <MovieDetailsPage movie={result.movie} />
        </>
    );
}

export default MovieDetailNavigation;
