import { Link, useParams } from "react-router-dom";

function MovieDetailNavigation() {
    const { tmdbId } = useParams();

    const movieId = Number(tmdbId);

    const isValidId =
        /^\d+$/.test(tmdbId || "") &&
        Number.isSafeInteger(movieId) &&
        movieId > 0 &&
        movieId <= 2147483647;

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

    return (
        <main className="container py-5">
            <h1>Movie Details</h1>
            <p>TMDB Movie ID: {movieId}</p>
            <p className="text-body-secondary">
                Movie details will be implemented in Story F.
            </p>
            <Link to="/movies" className="btn btn-outline-primary">
                Back to Movie Discovery
            </Link>
        </main>
    );
}

export default MovieDetailNavigation;
