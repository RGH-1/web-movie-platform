import { useEffect, useRef, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import { addMovieToList, getMyLists } from '../services/lists';
import { useAuth } from '../hooks/useAuth';
import { completionFromError } from '../utils/profileCompletion';
import MoviePagination from './MoviePagination';

export default function AddToList({ movie }) {
    const { user, profileCompletion } = useAuth();
    const location = useLocation();
    const [opened, setOpened] = useState(false);
    const [page, setPage] = useState(1);
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState(null);
    const [unauthenticated, setUnauthenticated] = useState(false);
    const [incomplete, setIncomplete] = useState(false);
    const mutation = useRef(null);
    useEffect(() => () => mutation.current?.abort(), []);
    useEffect(() => {
        if (!opened || !user || incomplete || profileCompletion?.complete === false) return;
        const controller = new AbortController();
        getMyLists(page, { signal: controller.signal }).then((data) => {
            if (!controller.signal.aborted) setResult(data);
        }).catch((err) => {
            if (controller.signal.aborted) return;
            if (err.response?.status === 401) setUnauthenticated(true);
            else setError('Could not load your lists. Please try again.');
        }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [opened, user, page, attempt, incomplete, profileCompletion?.complete]);

    function load(nextPage = page) {
        setOpened(true);
        setLoading(true);
        setResult(null);
        setError('');
        setSelected('');
        setSuccess(null);
        setPage(nextPage);
        setAttempt((value) => value + 1);
    }

    async function add(event) {
        event.preventDefault();
        if (!selected || busy) return;
        const controller = new AbortController();
        mutation.current = controller;
        setBusy(true);
        setError('');
        setSuccess(null);
        try {
            await addMovieToList(selected, movie, { signal: controller.signal });
            if (!controller.signal.aborted) setSuccess(selected);
        } catch (err) {
            if (controller.signal.aborted) return;
            if (err.response?.status === 401) setUnauthenticated(true);
            else if (completionFromError(err)) setIncomplete(true);
            else setError(err.response?.status === 404
                ? 'The selected list or movie is no longer available. Refresh your lists or movie details.'
                : 'Could not add this movie. Please try again.');
        } finally { if (!controller.signal.aborted) setBusy(false); }
    }

    if (unauthenticated) return <Navigate to="/login" replace state={{ from: location }} />;
    return (
        <section className="card mt-4" aria-label="Add to list">
            <div className="card-body">
                <h2 className="h4">Add to list</h2>
                {!user ? <Link to="/login" state={{ from: location }}>Log in to add to a list</Link>
                    : incomplete || profileCompletion?.complete === false ? <Link to="/profile?edit=1">Complete your profile to add to a list</Link>
                    : movie.catalog_deactivated || movie.is_active === false ? <p>This movie is unavailable for new list entries.</p>
                    : <>
                        {!opened && <button type="button" className="btn btn-outline-primary" onClick={() => load()}>Choose a list</button>}
                        {loading && <p role="status">Loading your lists...</p>}
                        {error && <div className="alert alert-danger" role="alert">{error}</div>}
                        {opened && !loading && <button type="button" className="btn btn-outline-secondary mb-3" disabled={busy} onClick={() => load()}>Refresh lists</button>}
                        {result && result.data.length === 0 && <p>You have no lists on this page. <Link to="/my-lists">Create or manage lists</Link></p>}
                        {result && result.data.length > 0 && <form onSubmit={add}>
                            <label htmlFor="add-list-id" className="form-label">Choose one of your lists</label>
                            <select id="add-list-id" className="form-select mb-3" required disabled={busy} value={selected} onChange={(event) => { setSelected(event.target.value); setSuccess(null); }}>
                                <option value="">Select a list</option>
                                {result.data.map((list) => <option key={list.id} value={list.id}>{list.name} ({list.is_public ? 'Public' : 'Private'})</option>)}
                            </select>
                            <button type="submit" className="btn btn-primary" disabled={busy || !selected}>{busy ? 'Adding...' : 'Add movie'}</button>
                            <p className="small text-body-secondary mt-2">If the movie is already in this list, it will appear only once.</p>
                        </form>}
                        {result?.meta.last_page > 1 && <MoviePagination page={page} totalPages={result.meta.last_page} loading={busy} onPageChange={load} label="List selector pagination" />}
                        {success && <p className="alert alert-success mt-3" role="status">Movie saved to your list. <Link to={`/lists/${success}`}>View list</Link></p>}
                    </>}
            </div>
        </section>
    );
}
