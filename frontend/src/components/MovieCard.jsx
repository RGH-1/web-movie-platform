function MovieCard({ movie }) {
    const releaseYear = movie.release_date
        ? movie.release_date.slice(0, 4)
        : 'Unknown year';

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

            <div className="card-body text-start">
                <h2 className="card-title fs-5 mb-2">
                    {movie.title}
                </h2>

                <p className="text-body-secondary small mb-2">
                    {releaseYear}
                </p>

                <p className="card-text">
                    {movie.overview?.trim() || 'No overview available.'}
                </p>
            </div>
        </article>
    );
}

export default MovieCard;
