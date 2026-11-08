import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import MovieCard from '../components/MovieCard';
import { searchMovies } from '../services/movies';

function MovieDiscovery() {
    const [query, setQuery] = useState('');
    const [submittedQuery, setSubmittedQuery] = useState('');
    const [movies, setMovies] = useState([]);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(0);
    const [totalResults, setTotalResults] = useState(0);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [validationError, setValidationError] = useState('');
    const [hasSearched, setHasSearched] = useState(false);
    const requestId = useRef(0);

    useEffect(() => {
        return () => {
            requestId.current += 1;
        };
    }, []);

    async function performSearch(searchQuery, requestedPage = 1) {
        const currentRequest = ++requestId.current;

        setLoading(true);
        setError('');
        setValidationError('');
        setHasSearched(true);
        setMovies([]);

        try {
            const result = await searchMovies(searchQuery, requestedPage);

            if (currentRequest !== requestId.current) return;

            setMovies(result.data);
            setPage(result.meta.page);
            setTotalPages(result.meta.total_pages);
            setTotalResults(result.meta.total_results);
        } catch (err) {
            if (currentRequest !== requestId.current) return;

            const status = err.response?.status;

            if (status === 422) {
                setValidationError(
                    err.response?.data?.errors?.query?.[0] ||
                    err.response?.data?.errors?.page?.[0] ||
                    'Please enter a valid movie title.'
                );
            } else if (status === 401) {
                setError('Please log in to search for movies.');
            } else if (status === 403) {
                setError('Please complete your profile before searching.');
            } else if (status === 503) {
                setError('Movie search is busy. Please try again shortly.');
            } else {
                setError('Movie search is temporarily unavailable. Please try again.');
            }
        } finally {
            if (currentRequest === requestId.current) {
                setLoading(false);
            }
        }
    }

    function handleSubmit(event) {
        event.preventDefault();

        const trimmedQuery = query.trim();

        if (!trimmedQuery) {
            setValidationError('Please enter a movie title.');
            return;
        }

        if (trimmedQuery.length > 200) {
            setValidationError('Movie title cannot exceed 200 characters.');
            return;
        }

        setSubmittedQuery(trimmedQuery);
        setPage(1);
        setTotalPages(0);
        setTotalResults(0);
        performSearch(trimmedQuery, 1);
    }

    function changePage(nextPage) {
        if (loading || nextPage < 1 || nextPage > totalPages) return;

        performSearch(submittedQuery, nextPage);
    }

    return (
        <main className="container py-4 py-md-5 text-start">
            <div className="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 className="fs-2 mb-2">Discover Movies</h1>
                    <p className="text-body-secondary">
                        Search for movies and explore their details.
                    </p>
                </div>
                <Link to="/" className="btn btn-outline-secondary">
                    Home
                </Link>
            </div>

            <form onSubmit={handleSubmit} className="mb-4" role="search">
                <label htmlFor="movie-query" className="form-label">
                    Movie title
                </label>

                <div className="input-group">
                    <input
                        id="movie-query"
                        type="search"
                        className={`form-control ${validationError ? 'is-invalid' : ''}`}
                        placeholder="Search movies..."
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setValidationError('');
                        }}
                        disabled={loading}
                        maxLength={201}
                    />
                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={loading}
                    >
                        Search
                    </button>
                </div>

                {validationError && (
                    <div className="text-danger small mt-2" role="alert">
                        {validationError}
                    </div>
                )}
            </form>

            {loading && (
                <div className="text-center py-5" role="status">
                    <div className="spinner-border text-primary" aria-hidden="true" />
                    <p className="mt-3">Searching for movies...</p>
                </div>
            )}

            {error && (
                <div className="alert alert-danger" role="alert">
                    {error}
                    {error.includes('log in') && (
                        <Link to="/login" className="ms-2">
                            Log in
                        </Link>
                    )}
                </div>
            )}

            {!loading && !error && hasSearched && (
                <>
                    <div className="mb-4">
                        <h2 className="fs-5 mb-1">
                            Results for "{submittedQuery}"
                        </h2>
                        <p className="text-body-secondary small">
                            {totalResults} movie(s) found
                        </p>
                    </div>

                    {movies.length === 0 ? (
                        <div className="alert alert-info" role="status">
                            {totalResults === 0
                                ? 'No movies found. Try another search.'
                                : 'No movies on this page. Try a previous page.'}
                        </div>
                    ) : (
                        <div className="row g-4">
                            {movies.map((movie) => (
                                <div
                                    key={movie.tmdb_id}
                                    className="col-12 col-sm-6 col-md-4 col-lg-3"
                                >
                                    <MovieCard movie={movie} />
                                </div>
                            ))}
                        </div>
                    )}

                    {totalPages > 1 && (
                        <nav
                            className="d-flex justify-content-center align-items-center gap-3 mt-5"
                            aria-label="Movie results pagination"
                        >
                            <button
                                type="button"
                                className="btn btn-outline-primary"
                                disabled={page <= 1 || loading}
                                onClick={() => changePage(page - 1)}
                            >
                                Previous
                            </button>

                            <span>
                                Page {page} of {totalPages}
                            </span>

                            <button
                                type="button"
                                className="btn btn-outline-primary"
                                disabled={page >= totalPages || loading}
                                onClick={() => changePage(page + 1)}
                            >
                                Next
                            </button>
                        </nav>
                    )}
                </>
            )}
        </main>
    );
}

export default MovieDiscovery;
