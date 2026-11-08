
import { Link } from 'react-router-dom';

function MovieCard({ movie }) {
    const releaseYear = movie.release_date
        ? movie.release_date.slice(0, 4)
        : 'Unknown year';

    const movieId = Number(movie.tmdb_id);
    const hasValidId = Number.isSafeInteger(movieId) && movieId > 0;

    return (
        <article className="card h-100 shadow-sm">
            {movie.poster_url ? (
                <img
                    src={movie.poster_url}
                    alt={`${movie.title} poster`}
                    className="card-img-top"
                    style={{
                        aspectRatio: '2 / 3',
                        objectFit: 'cover',
                    }}
                    loading="lazy"
                />
            ) : (
                <div
                    className="bg-body-tertiary d-flex align-items-center justify-content-center text-body-secondary"
                    style={{ aspectRatio: '2 / 3' }}
                    role="img"
                    aria-label={`No poster available for ${movie.title}`}
                >
                    No poster available
                </div>
            )}

            <div className="card-body text-start d-flex flex-column">
                <h2 className="card-title fs-5 mb-2">
                    {movie.title}
                </h2>

                <p className="text-body-secondary small mb-2">
                    {releaseYear}
                </p>

                <p className="card-text">
                    {movie.overview?.trim() || 'No overview available.'}
                </p>

                <div className="mt-auto pt-3">
                    {hasValidId ? (
                        <Link
                            to={`/movies/${movieId}`}
                            className="btn btn-primary w-100"
                            aria-label={`View details for ${movie.title}`}
                        >
                            View Details
                        </Link>
                    ) : (
                        <button
                            type="button"
                            className="btn btn-secondary w-100"
                            disabled
                        >
                            Details unavailable
                        </button>
                    )}
                </div>
            </div>
        </article>
    );
}

export default MovieCard;
