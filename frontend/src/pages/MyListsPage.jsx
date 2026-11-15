import { useEffect, useRef, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import { createList, deleteList, getMyLists, updateList } from '../services/lists';
import MoviePagination from '../components/MoviePagination';
import ProfileCompletionNotice from '../components/ProfileCompletionNotice';
import { useAuth } from '../hooks/useAuth';
import { completionFromError } from '../utils/profileCompletion';

export default function MyListsPage() {
    const location = useLocation();
    const { profileCompletion } = useAuth();
    const [completion, setCompletion] = useState(null);
    const [page, setPage] = useState(1);
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [unauthenticated, setUnauthenticated] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState({ name: '', description: '', is_public: false });
    const [errors, setErrors] = useState({});
    const [deleting, setDeleting] = useState(null);
    const mutation = useRef(null);
    const writeBlocked = (completion ?? profileCompletion)?.complete === false;

    useEffect(() => () => mutation.current?.abort(), []);

    useEffect(() => {
        const controller = new AbortController();
        getMyLists(page, { signal: controller.signal }).then((data) => {
            if (controller.signal.aborted) return;
            if (data.data.length === 0 && page > data.meta.last_page) {
                setPage(Math.max(1, data.meta.last_page));
                return;
            }
            setResult(data);
            setLoading(false);
        }).catch((err) => {
            if (controller.signal.aborted) return;
            if (err.response?.status === 401) setUnauthenticated(true);
            else setError('Your lists could not be loaded. Please try again.');
            setLoading(false);
        });
        return () => controller.abort();
    }, [page, attempt]);

    function reload(nextPage = page) {
        setLoading(true);
        setResult(null);
        setPage(nextPage);
        setAttempt((value) => value + 1);
    }

    function openForm(list = null) {
        setEditing(list ? list.id : 'new');
        setForm({ name: list?.name ?? '', description: list?.description ?? '', is_public: list?.is_public ?? false });
        setErrors({});
        setError('');
        setSuccess('');
        setDeleting(null);
    }

    async function save(event) {
        event.preventDefault();
        if (busy || writeBlocked) return;
        const name = form.name.trim();
        if (!name || Array.from(name).length > 255 || Array.from(form.description).length > 5000) {
            setErrors({
                ...(!name || Array.from(name).length > 255 ? { name: ['Enter a list name of 1 to 255 characters.'] } : {}),
                ...(Array.from(form.description).length > 5000 ? { description: ['Description cannot exceed 5000 characters.'] } : {}),
            });
            return;
        }
        await write(async (signal) => {
            const fields = { name, description: form.description.trim() || null, is_public: form.is_public };
            if (editing === 'new') await createList(fields, { signal });
            else await updateList(editing, fields, { signal });
        }, 'List saved.', editing === 'new' ? 1 : page);
    }

    async function write(action, message, nextPage) {
        if (busy || writeBlocked) return;
        const controller = new AbortController();
        mutation.current = controller;
        setBusy(true);
        setError('');
        setErrors({});
        setSuccess('');
        try {
            await action(controller.signal);
            if (controller.signal.aborted) return;
            setEditing(null);
            setDeleting(null);
            setSuccess(message);
            reload(nextPage);
        } catch (err) {
            if (controller.signal.aborted) return;
            if (err.response?.status === 401) setUnauthenticated(true);
            else if (err.response?.status === 422) {
                setErrors(err.response.data?.errors ?? {});
                setError('Please check the list details.');
            } else if (completionFromError(err)) setCompletion(completionFromError(err));
            else setError(err.response?.status === 404
                ? 'This list is no longer available. Refresh your lists and try again.'
                : 'Your changes could not be saved. Please try again.');
        } finally {
            if (!controller.signal.aborted) setBusy(false);
        }
    }

    if (unauthenticated) return <Navigate to="/login" replace state={{ from: location }} />;

    return (
        <main className="container py-4 py-md-5 text-start" aria-labelledby="lists-heading">
            <Link to="/" className="d-inline-block mb-3">Back to home</Link>
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <h1 id="lists-heading" className="h2 mb-0">My Lists</h1>
                <button type="button" className="btn btn-primary" disabled={busy || loading || writeBlocked}
                    onClick={() => openForm()}>Create list</button>
            </div>
            <ProfileCompletionNotice completion={completion ?? profileCompletion} />
            {success && <p className="alert alert-success" role="status">{success}</p>}
            {error && <div className="alert alert-danger" role="alert">{error}</div>}
            {editing !== null && (
                <section className="card mb-4" aria-labelledby="list-form-heading">
                    <div className="card-body">
                        <h2 id="list-form-heading" className="h5">{editing === 'new' ? 'Create a list' : 'Edit list'}</h2>
                        <form onSubmit={save}>
                            <fieldset disabled={busy || writeBlocked}>
                                <div className="mb-3">
                                    <label htmlFor="list-name" className="form-label">List name</label>
                                    <input id="list-name" className="form-control" required value={form.name}
                                        aria-invalid={!!errors.name} aria-describedby={errors.name ? 'list-name-errors' : undefined}
                                        onChange={(event) => setForm({ ...form, name: event.target.value })} />
                                    {errors.name && <div id="list-name-errors" className="text-danger" role="alert">{errors.name.join(' ')}</div>}
                                </div>
                                <div className="mb-3">
                                    <label htmlFor="list-description" className="form-label">Description (optional)</label>
                                    <textarea id="list-description" className="form-control" rows={3} value={form.description}
                                        aria-invalid={!!errors.description} aria-describedby={errors.description ? 'list-description-errors' : undefined}
                                        onChange={(event) => setForm({ ...form, description: event.target.value })} />
                                    {errors.description && <div id="list-description-errors" className="text-danger" role="alert">{errors.description.join(' ')}</div>}
                                </div>
                                <div className="form-check mb-3">
                                    <input id="list-public" type="checkbox" className="form-check-input" checked={form.is_public}
                                        onChange={(event) => setForm({ ...form, is_public: event.target.checked })} />
                                    <label htmlFor="list-public" className="form-check-label">Public - other signed-in users can view this list</label>
                                    {errors.is_public && <p className="text-danger" role="alert">{errors.is_public.join(' ')}</p>}
                                </div>
                                <button type="submit" className="btn btn-primary me-2">{busy ? 'Saving...' : 'Save list'}</button>
                                <button type="button" className="btn btn-outline-secondary" onClick={() => setEditing(null)}>Cancel</button>
                            </fieldset>
                        </form>
                    </div>
                </section>
            )}
            {loading && <p role="status">Loading your lists...</p>}
            {!loading && <button type="button" className="btn btn-outline-secondary mb-3" disabled={busy}
                onClick={() => { setError(''); reload(); }}>Refresh lists</button>}
            {!loading && result && (
                <>
                    {result.data.length === 0 && <p className="alert alert-info" role="status">You have no lists yet. Create your first list.</p>}
                    <div className="row g-3">
                        {result.data.map((list) => (
                            <div key={list.id} className="col-12 col-md-6 col-lg-4">
                                <article className="card h-100" aria-label={list.name}>
                                    <div className="card-body d-flex flex-column">
                                        <h2 className="h5 text-break">{list.name}</h2>
                                        <p className="text-break" style={{ whiteSpace: 'pre-wrap' }}>{list.description || 'No description.'}</p>
                                        <p><span className={`badge ${list.is_public ? 'text-bg-success' : 'text-bg-secondary'}`}>{list.is_public ? 'Public' : 'Private'}</span></p>
                                        <p>{list.movie_count} movie(s)</p>
                                        <div className="mt-auto d-flex gap-2">
                                            <button type="button" className="btn btn-outline-primary" disabled={busy || writeBlocked || editing !== null || deleting !== null}
                                                onClick={() => openForm(list)}>Edit</button>
                                            <button type="button" className="btn btn-outline-danger" disabled={busy || writeBlocked || editing !== null || deleting !== null}
                                                onClick={() => { setDeleting(list.id); setError(''); setSuccess(''); }}>Delete</button>
                                        </div>
                                        {deleting === list.id && (
                                            <div className="alert alert-warning mt-3 mb-0" role="group" aria-label={`Confirm deletion of ${list.name}`}>
                                                <p>Delete this list? Its entries will be removed, but catalog movies will remain.</p>
                                                <button type="button" className="btn btn-danger me-2" disabled={busy}
                                                    onClick={() => write((signal) => deleteList(list.id, { signal }), 'List deleted.', page)}>
                                                    {busy ? 'Deleting...' : 'Confirm delete'}
                                                </button>
                                                <button type="button" className="btn btn-outline-secondary" disabled={busy} onClick={() => setDeleting(null)}>Cancel</button>
                                            </div>
                                        )}
                                    </div>
                                </article>
                            </div>
                        ))}
                    </div>
                    {result.meta.last_page > 1 && <MoviePagination page={page} totalPages={result.meta.last_page}
                        loading={busy || editing !== null || deleting !== null} onPageChange={(nextPage) => { setError(''); reload(nextPage); }} label="My lists pagination" />}
                </>
            )}
        </main>
    );
}
