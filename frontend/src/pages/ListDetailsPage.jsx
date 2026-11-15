import { useEffect, useRef, useState } from 'react';
import { Link, Navigate, useLocation, useParams } from 'react-router-dom';
import { getList, removeMovieFromList } from '../services/lists';
import { useAuth } from '../hooks/useAuth';
import { completionFromError } from '../utils/profileCompletion';
import MovieCard from '../components/MovieCard';
import MoviePagination from '../components/MoviePagination';
import ProfileCompletionNotice from '../components/ProfileCompletionNotice';

export default function ListDetailsPage() {
    const { listId } = useParams();
    return <ListDetails key={listId} listId={listId} />;
}

function ListDetails({ listId }) {
    const { user, profileCompletion } = useAuth();
    const location = useLocation();
    const valid = /^[1-9][0-9]{0,18}$/.test(listId) && (listId.length < 19 || listId <= '9223372036854775807');
    const [page, setPage] = useState(1);
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [missing, setMissing] = useState(!valid);
    const [unauthenticated, setUnauthenticated] = useState(false);
    const [busy, setBusy] = useState(null);
    const [completion, setCompletion] = useState(null);
    const [success, setSuccess] = useState('');
    const mutation = useRef(null);
    useEffect(() => () => mutation.current?.abort(), []);
    useEffect(() => {
        if (!valid) return;
        const controller = new AbortController();
        getList(listId, page, { signal: controller.signal }).then((data) => {
            if (controller.signal.aborted) return;
            if (data.data.movies.length === 0 && page > data.meta.last_page) {
                setPage(Math.max(1, data.meta.last_page));
                return;
            }
            setResult(data);
            setLoading(false);
        }).catch((err) => {
            if (controller.signal.aborted) return;
            if (err.response?.status === 401) setUnauthenticated(true);
            else if (err.response?.status === 404) setMissing(true);
            else setError('This list could not be loaded. Please try again.');
            setLoading(false);
        });
        return () => controller.abort();
    }, [listId, page, attempt, valid]);

    function reload(nextPage = page) {
        setResult(null);
        setLoading(true);
        setError('');
        setPage(nextPage);
        setAttempt((value) => value + 1);
    }

    async function remove(movieId) {
        if (busy) return;
        const controller = new AbortController();
        mutation.current = controller;
        setBusy(movieId);
        setError('');
        setSuccess('');
        try {
            await removeMovieFromList(listId, movieId, { signal: controller.signal });
            if (!controller.signal.aborted) { setSuccess('Movie removed from list.'); reload(); }
        } catch (err) {
            if (controller.signal.aborted) return;
            if (err.response?.status === 401) setUnauthenticated(true);
            else if (err.response?.status === 404) { setResult(null); setMissing(true); }
            else if (completionFromError(err)) setCompletion(completionFromError(err));
            else setError('The movie could not be removed. Please try again.');
        } finally { if (!controller.signal.aborted) setBusy(null); }
    }

    if (unauthenticated) return <Navigate to="/login" replace state={{ from: location }} />;
    const owner = result && String(result.data.author.id) === String(user?.id);
    return (
        <main className="container py-4 py-md-5 text-start">
            <Link to="/my-lists">Back to My Lists</Link>
            {missing ? <div className="alert alert-warning mt-3" role="alert">List not found or unavailable to your account.</div> : <>
                {loading && <p className="mt-3" role="status">Loading list...</p>}
                {error && <div className="alert alert-danger mt-3" role="alert">{error}</div>}
                {success && <p className="alert alert-success mt-3" role="status">{success}</p>}
                {!loading && <button type="button" className="btn btn-outline-secondary my-3" disabled={busy !== null} onClick={() => reload()}>Refresh list</button>}
                {result && <>
                    <h1 className="h2 text-break">{result.data.name}</h1>
                    <p>By {result.data.author.name}</p>
                    <p><span className="badge text-bg-secondary">{result.data.is_public ? 'Public' : 'Private'}</span> - {result.data.movie_count} movie(s)</p>
                    <p className="text-break" style={{ whiteSpace: 'pre-wrap' }}>{result.data.description || 'No description.'}</p>
                    {owner && <ProfileCompletionNotice completion={completion ?? profileCompletion} />}
                    {owner && <p><Link to="/my-lists">Manage your lists</Link></p>}
                    {result.data.movies.length === 0 && <p role="status">This list has no movies yet.</p>}
                    <div className="row g-4">
                        {result.data.movies.map((movie) => <div className="col-12 col-sm-6 col-md-4 col-lg-3 d-flex flex-column" key={movie.local_movie_id}>
                            <div className="flex-grow-1"><MovieCard movie={movie} /></div>
                            {movie.is_active === false && <p className="text-body-secondary mt-2">This movie is no longer available.</p>}
                            {owner && <button type="button" className="btn btn-outline-danger mt-2 w-100" disabled={busy !== null || (completion ?? profileCompletion)?.complete === false}
                                onClick={() => remove(movie.local_movie_id)}>{busy === movie.local_movie_id ? 'Removing...' : `Remove ${movie.title} from list`}</button>}
                        </div>)}
                    </div>
                    {result.meta.last_page > 1 && <MoviePagination page={page} totalPages={result.meta.last_page} loading={busy !== null} onPageChange={reload} label="List movies pagination" />}
                </>}
            </>}
        </main>
    );
}
