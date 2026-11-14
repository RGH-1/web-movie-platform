import { useEffect, useState } from 'react';
import { getPopularMovies, getUpcomingMovies } from '../services/movies';
import MovieCard from './MovieCard';
import MoviePagination from './MoviePagination';

function MovieFeed({ category }) {
    const [page, setPage] = useState(1);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const title = category === 'popular' ? 'Popular' : 'Upcoming';

    useEffect(() => {
        let active = true;

        const load = category === 'popular' ? getPopularMovies : getUpcomingMovies;
        load(page).then((data) => {
            if (active) setResult(data);
        }).catch((err) => {
            if (active) setError(err.response?.status === 503
                ? 'Movie listings are busy. Please try again shortly.'
                : 'Movie listings are temporarily unavailable. Please try again.');
        }).finally(() => {
            if (active) setLoading(false);
        });

        // Ignore requests completed after changing page/category or leaving discovery.
        return () => { active = false; };
    }, [category, page, attempt]);

    function loadPage(nextPage = page) {
        setLoading(true);
        setError('');
        setResult(null);
        setPage(nextPage);
        if (nextPage === page) setAttempt(attempt + 1);
    }

    return (
        <section aria-label={`${title} movies`} aria-busy={loading}>
            <h2 className="fs-5 mb-3">{title} movies</h2>
            {loading && <p role="status">Loading {category} movies...</p>}
            {error && (
                <div className="alert alert-danger" role="alert">
                    {error}
                    <button type="button" className="btn btn-outline-danger ms-2"
                        onClick={() => loadPage()}>Try again</button>
                </div>
            )}
            {result && (
                <>
                    {result.data.length === 0 ? (
                        <p className="alert alert-info" role="status">
                            No {category} movies on this page.
                        </p>
                    ) : (
                        <div className="row g-4">
                            {result.data.map((movie) => (
                                <div key={movie.tmdb_id} className="col-12 col-sm-6 col-md-4 col-lg-3">
                                    <MovieCard movie={movie} />
                                </div>
                            ))}
                        </div>
                    )}
                </>
            )}
            {!loading && (result?.meta.total_pages > 1 || page > 1) && (
                <MoviePagination page={page} totalPages={result?.meta.total_pages || page}
                    loading={loading} onPageChange={loadPage} label={`${title} movies pagination`} />
            )}
        </section>
    );
}

export default MovieFeed;
