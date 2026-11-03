import { useState } from 'react';
import api from '../services/api';

export default function TmdbIntegrationTest() {
    const [query, setQuery] = useState('Interstellar');
    const [movies, setMovies] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [hasSearched, setHasSearched] = useState(false);

    async function handleSearch(event) {
        event.preventDefault();

        const searchText = query.trim();

        if (!searchText || loading) {
            return;
        }

        setLoading(true);
        setError('');
        setMovies([]);
        setHasSearched(false);

        try {
            const response = await api.get('/integration/tmdb/search', {
                params: { query: searchText },
            });

            if (!Array.isArray(response.data.results)) {
                throw new Error('Unexpected movie search response.');
            }

            setMovies(response.data.results);
            setHasSearched(true);
        } catch {
            setError('Movie search is unavailable. Check the backend and try again.');
        } finally {
            setLoading(false);
        }
    }

    return (
        <section className="mt-5" aria-labelledby="tmdb-test-heading">
            <h2 id="tmdb-test-heading">Temporary TMDB integration test</h2>
            <p>
                Search through Laravel to verify the React-to-TMDB integration.
            </p>

            <form onSubmit={handleSearch} className="mb-3">
                <label htmlFor="tmdb-query" className="form-label">
                    Movie title
                </label>

                <div className="d-flex gap-2">
                    <input
                        id="tmdb-query"
                        type="text"
                        className="form-control"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        maxLength={200}
                        required
                        disabled={loading}
                    />

                    <button
                        type="submit"
                        className="btn btn-primary"
                        disabled={loading || !query.trim()}
                    >
                        {loading ? 'Searching…' : 'Test movie search'}
                    </button>
                </div>
            </form>

            <div aria-live="polite">
                {loading && <p role="status">Loading movies…</p>}

                {hasSearched && movies.length === 0 && (
                    <p>No movies found. Try another title.</p>
                )}
            </div>

            {error && (
                <p className="alert alert-danger" role="alert">
                    {error}
                </p>
            )}

            <ul className="row g-3 list-unstyled">
                {movies.map((movie) => (
                    <li key={movie.id} className="col-12 col-sm-6 col-md-4">
                        <article className="card h-100">
                            {movie.poster_path ? (
                                <img
                                    src={`https://image.tmdb.org/t/p/w342${movie.poster_path}`}
                                    alt={`${movie.title} poster`}
                                    className="card-img-top"
                                    loading="lazy"
                                    onError={(event) => {
                                        event.currentTarget.hidden = true;
                                    }}
                                />
                            ) : (
                                <div className="p-4 text-center text-body-secondary">
                                    No poster available
                                </div>
                            )}

                            <div className="card-body">
                                <h3 className="h5 card-title">{movie.title}</h3>
                            </div>
                        </article>
                    </li>
                ))}
            </ul>
        </section>
    );
}