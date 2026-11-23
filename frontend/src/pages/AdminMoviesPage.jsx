import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import AdminMovieError from '../components/AdminMovieError';
import AdminMovieForm from '../components/AdminMovieForm';
import MoviePagination from '../components/MoviePagination';
import { useAuth } from '../hooks/useAuth';
import { deactivateAdminMovie, getAdminMovies } from '../services/adminMovies';

const defaultFilters = { search: '', source: '', status: 'all' };

export default function AdminMoviesPage() {
    const { user } = useAuth();
    if (user?.can_access_admin !== true) {
        return (
            <main className="container py-4 text-start">
                <Link to="/">Back to home</Link>
                <h1 className="h2 mt-3">Movie management</h1>
                <p className="alert alert-warning" role="alert">Administrator access is required.</p>
            </main>
        );
    }

    return <AdminMovieCatalog />;
}

function Poster({ url }) {
    const [failed, setFailed] = useState(false);
    if (!url || failed) return <p className="small text-body-secondary border rounded p-2 mb-0 flex-shrink-0"
        style={{ width: 64 }}>No poster</p>;
    return <img src={url} alt="" width={64} height={96} className="rounded object-fit-cover flex-shrink-0"
        loading="lazy" onError={() => setFailed(true)} />;
}

function AdminMovieCatalog() {
    const [filters, setFilters] = useState(defaultFilters);
    const [request, setRequest] = useState({ ...defaultFilters, page: 1 });
    const [result, setResult] = useState(null);
    const [editor, setEditor] = useState(null);
    const [selected, setSelected] = useState(null);
    const [changeError, setChangeError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const mutation = useRef(null);

    useEffect(() => () => mutation.current?.abort(), []);

    useEffect(() => {
        const controller = new AbortController();
        getAdminMovies(request, { signal: controller.signal }).then((response) => {
            if (controller.signal.aborted) return;
            if (request.page > response.meta.last_page) {
                setRequest({ ...request, page: response.meta.last_page });
                return;
            }
            setResult({ request, response });
        }).catch((error) => {
            if (!controller.signal.aborted) setResult({ request, error });
        });
        return () => controller.abort();
    }, [request]);

    function applyFilters(event) {
        event.preventDefault();
        setNotice('');
        setRequest({ ...filters, search: filters.search.trim(), page: 1 });
    }

    async function confirmDeactivation() {
        if (!selected || mutation.current) return;
        const controller = new AbortController();
        mutation.current = controller;
        setBusy(true);
        setChangeError(null);
        try {
            await deactivateAdminMovie(selected.local_movie_id, { signal: controller.signal });
            if (controller.signal.aborted) return;
            setSelected(null);
            setNotice('Movie deactivated.');
            setRequest({ ...request });
        } catch (error) {
            if (!controller.signal.aborted) {
                if (error.response?.status === 403) {
                    setSelected(null);
                    setResult({ request, error });
                } else {
                    setChangeError(error);
                }
            }
        } finally {
            if (!controller.signal.aborted) {
                mutation.current = null;
                setBusy(false);
            }
        }
    }

    const current = result?.request === request ? result : null;
    const response = current?.response;
    const accessDenied = [401, 403].includes(current?.error?.response?.status);
    return (
        <main className="container py-4 py-md-5 text-start">
            <nav aria-label="Administrator navigation" className="d-flex flex-wrap gap-3">
                <Link to="/">Back to home</Link>
                <Link to="/admin/users">User management</Link>
            </nav>
            <header className="d-flex flex-wrap justify-content-between align-items-center gap-3 my-3">
                <h1 className="h2 mb-0">Movie management</h1>
                {response && !editor && <button type="button" className="btn btn-primary" disabled={!!selected}
                    onClick={() => { setNotice(''); setEditor({}); }}>Create local movie</button>}
            </header>

            {editor ? (
                <AdminMovieForm movieId={editor.movieId} onClose={() => setEditor(null)}
                    onSaved={(message) => {
                        setEditor(null);
                        setNotice(message);
                        setRequest({ ...request });
                    }} />
            ) : (
                <>
                    {!accessDenied && (
                        <form className="mb-4" onSubmit={applyFilters}>
                            <fieldset className="row g-2 align-items-end" disabled={!!selected}>
                                <div className="col-12 col-md-5">
                                    <label className="form-label" htmlFor="admin-movie-search">Search by title</label>
                                    <input type="search" className="form-control" id="admin-movie-search" maxLength={255}
                                        value={filters.search} onChange={(event) => setFilters({ ...filters, search: event.target.value })} />
                                </div>
                                <div className="col-6 col-md-3">
                                    <label className="form-label" htmlFor="admin-movie-source">Source</label>
                                    <select className="form-select" id="admin-movie-source" value={filters.source}
                                        onChange={(event) => setFilters({ ...filters, source: event.target.value })}>
                                        <option value="">All sources</option><option value="local">Local</option><option value="tmdb">TMDB</option>
                                    </select>
                                </div>
                                <div className="col-6 col-md-2">
                                    <label className="form-label" htmlFor="admin-movie-status">Status</label>
                                    <select className="form-select" id="admin-movie-status" value={filters.status}
                                        onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
                                        <option value="all">All</option><option value="active">Active</option><option value="deactivated">Deactivated</option>
                                    </select>
                                </div>
                                <div className="col-12 col-md-auto">
                                    <button type="submit" className="btn btn-outline-primary">Apply filters</button>
                                </div>
                            </fieldset>
                        </form>
                    )}
                    {!current && <p role="status">Loading movies…</p>}
                    {current?.error && <AdminMovieError error={current.error} message="Could not load catalog movies."
                        onRetry={() => setRequest({ ...request })} />}
                    {notice && <p role="status" className="alert alert-success">{notice}</p>}
                    {response?.data.length === 0 && <p role="status">No movies match your filters.</p>}
                    {response?.data.length > 0 && (
                        <div className="row g-3">
                            {response.data.map((movie) => {
                                const id = movie.local_movie_id;
                                const confirming = selected?.local_movie_id === id;
                                return (
                                    <div key={id} className="col-12 col-md-6 col-xl-4">
                                        <article className="card h-100">
                                            <div className="card-body">
                                                <div className="d-flex align-items-start gap-3 mb-3">
                                                    <Poster key={movie.poster_url} url={movie.poster_url} />
                                                    <div className="text-break">
                                                        <h2 className="h5 mb-1">{movie.title}</h2>
                                                        <p className="small text-body-secondary mb-2">{movie.release_date || 'Release date unavailable'}</p>
                                                        <div className="d-flex flex-wrap gap-1">
                                                            <span className="badge text-bg-light">{movie.source === 'tmdb' ? `TMDB · ${movie.tmdb_id}` : 'Local'}</span>
                                                            <span className={`badge ${movie.is_active ? 'text-bg-success' : 'text-bg-secondary'}`}>
                                                                {movie.is_active ? 'Active' : 'Deactivated'}
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                {confirming ? (
                                                    <section aria-label="Confirm deactivation" className="alert alert-warning mb-0">
                                                        <p>Deactivate this movie? It will be removed from the public catalog. Its history will be kept.</p>
                                                        {changeError && <AdminMovieError error={changeError} message="Could not deactivate this movie. Please try again." />}
                                                        <div className="d-flex flex-wrap gap-2">
                                                            <button type="button" className="btn btn-danger btn-sm" disabled={busy}
                                                                onClick={confirmDeactivation}>{busy ? 'Deactivating…' : 'Confirm deactivation'}</button>
                                                            <button type="button" className="btn btn-outline-dark btn-sm" disabled={busy}
                                                                onClick={() => setSelected(null)}>Cancel</button>
                                                        </div>
                                                    </section>
                                                ) : (
                                                    <div className="d-flex flex-wrap align-items-center gap-2">
                                                        <button type="button" className="btn btn-outline-primary btn-sm" disabled={!!selected}
                                                            aria-label={`Edit ${movie.title}`} onClick={() => {
                                                                setNotice('');
                                                                setEditor({ movieId: id });
                                                            }}>Edit</button>
                                                        {movie.is_active && (
                                                            <>
                                                                <button type="button" className="btn btn-outline-danger btn-sm" disabled={!!selected}
                                                                    aria-label={`Deactivate ${movie.title}`} onClick={() => {
                                                                        setNotice('');
                                                                        setChangeError(null);
                                                                        setSelected(movie);
                                                                    }}>Deactivate</button>
                                                                <Link to={`/movies/local/${id}`} aria-label={`View details for ${movie.title}`}>View details</Link>
                                                            </>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        </article>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                    {response?.meta.last_page > 1 && <MoviePagination page={response.meta.current_page}
                        totalPages={response.meta.last_page} label="Admin catalog pagination" loading={!!selected}
                        onPageChange={(page) => { setNotice(''); setRequest({ ...request, page }); }} />}
                </>
            )}
        </main>
    );
}
