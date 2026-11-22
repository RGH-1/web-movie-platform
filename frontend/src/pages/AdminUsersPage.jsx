import { useEffect, useRef, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import MoviePagination from '../components/MoviePagination';
import { useAuth } from '../hooks/useAuth';
import { getAdminUsers, updateAdminUserStatus } from '../services/adminUsers';

export default function AdminUsersPage() {
    const { user } = useAuth();
    if (user?.can_access_admin !== true) {
        return (
            <main className="container py-4 text-start">
                <Link to="/">Back to home</Link>
                <h1 className="h2 mt-3">User management</h1>
                <p role="alert" className="alert alert-warning">Administrator access is required.</p>
            </main>
        );
    }

    return <UserManagement />;
}

function UserManagement() {
    const location = useLocation();
    const [search, setSearch] = useState('');
    const [request, setRequest] = useState({ page: 1, search: '' });
    const [result, setResult] = useState(null);
    const [selected, setSelected] = useState(null);
    const [busy, setBusy] = useState(false);
    const [changeError, setChangeError] = useState('');
    const [notice, setNotice] = useState('');
    const mutation = useRef(null);

    useEffect(() => () => mutation.current?.abort(), []);

    useEffect(() => {
        const controller = new AbortController();
        getAdminUsers(request, { signal: controller.signal }).then((response) => {
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

    function submitSearch(event) {
        event.preventDefault();
        setNotice('');
        setRequest({ page: 1, search: search.trim() });
    }

    async function confirmChange() {
        if (!selected || mutation.current) return;
        const controller = new AbortController();
        mutation.current = controller;
        setBusy(true);
        setChangeError('');
        setNotice('');
        try {
            const updated = await updateAdminUserStatus(selected.id, selected.status === 'enabled' ? 'disabled' : 'enabled',
                { signal: controller.signal });
            if (controller.signal.aborted) return;
            setResult((previous) => ({
                ...previous,
                response: {
                    ...previous.response,
                    data: previous.response.data.map((user) => user.id === updated.id ? updated : user),
                },
            }));
            setSelected(null);
            setNotice(`Account ${updated.status}.`);
        } catch (error) {
            if (controller.signal.aborted) return;
            if (error.response?.status === 401) {
                setResult({ request, error });
                return;
            }
            setChangeError(error.response?.status === 403
                ? 'This account status cannot be changed.'
                : 'Could not change the account status. Please try again.');
        } finally {
            if (!controller.signal.aborted) {
                mutation.current = null;
                setBusy(false);
            }
        }
    }

    const current = result?.request === request ? result : null;
    const status = current?.error?.response?.status;
    if (status === 401) return <Navigate to="/login" replace state={{ from: location }} />;
    const response = current?.response;

    return (
        <main className="container py-4 py-md-5 text-start">
            <Link to="/">Back to home</Link>
            <h1 className="h2 mt-3">User management</h1>
            {status !== 403 && (
                <form className="mb-4" onSubmit={submitSearch}>
                    <label className="form-label" htmlFor="admin-user-search">Search by name or email</label>
                    <div className="d-flex flex-wrap gap-2">
                        <input type="search" id="admin-user-search" className="form-control w-auto mw-100"
                            value={search} maxLength={100} onChange={(event) => setSearch(event.target.value)} disabled={!!selected} />
                        <button className="btn btn-primary" disabled={!!selected}>Search</button>
                    </div>
                </form>
            )}

            {!current && <p role="status">Loading users…</p>}
            {current?.error && (
                <div role="alert" className="alert alert-danger">
                    {status === 403 ? 'Administrator access is required.' : 'Could not load users.'}
                    {status !== 403 && <button type="button" className="btn btn-outline-danger btn-sm d-block mt-2"
                        onClick={() => setRequest({ ...request })}>Try again</button>}
                </div>
            )}
            {notice && <p role="status" className="alert alert-success">{notice}</p>}
            {selected && (
                <section className="alert alert-warning text-break" aria-labelledby="admin-user-confirmation">
                    <h2 className="h5" id="admin-user-confirmation">
                        {selected.status === 'enabled' ? 'Disable' : 'Enable'} {selected.name}?
                    </h2>
                    {selected.email && <p>{selected.email}</p>}
                    <p>{selected.status === 'enabled'
                        ? 'This user will be signed out and will lose access to protected features.'
                        : 'This user will be able to sign in again.'}</p>
                    {changeError && <p role="alert" className="text-danger">{changeError}</p>}
                    <div className="d-flex flex-wrap gap-2">
                        <button type="button" className={`btn ${selected.status === 'enabled' ? 'btn-danger' : 'btn-primary'}`}
                            onClick={confirmChange} disabled={busy}>
                            {busy ? 'Saving…' : selected.status === 'enabled' ? 'Confirm disable' : 'Confirm enable'}
                        </button>
                        <button type="button" className="btn btn-outline-dark" disabled={busy} onClick={() => setSelected(null)}>Cancel</button>
                    </div>
                </section>
            )}
            {response?.data.length === 0 && <p role="status">No users found.</p>}
            {response?.data.length > 0 && (
                <div className="table-responsive">
                    <table className="table align-middle">
                        <caption className="visually-hidden">User accounts</caption>
                        <thead>
                            <tr><th scope="col">Account</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Action</th></tr>
                        </thead>
                        <tbody>
                            {response.data.map((user) => (
                                <tr key={user.id}>
                                    <th scope="row" className="text-break">
                                        {user.name}
                                        <span className="d-block fw-normal small">{user.email ?? 'No email'}</span>
                                        {user.created_at && <small className="d-block fw-normal text-body-secondary">
                                            Joined {new Date(user.created_at).toLocaleDateString()}
                                        </small>}
                                    </th>
                                    <td>{user.is_admin ? 'Administrator' : 'Member'}</td>
                                    <td>
                                        {user.status === 'enabled' ? 'Enabled' : 'Disabled'}
                                        {user.disabled_at && <small className="d-block text-body-secondary">
                                            {new Date(user.disabled_at).toLocaleDateString()}
                                        </small>}
                                    </td>
                                    <td>
                                        {user.is_admin ? 'Protected' : (
                                            <button type="button"
                                                className={`btn btn-sm ${user.status === 'enabled' ? 'btn-outline-danger' : 'btn-outline-primary'}`}
                                                disabled={!!selected}
                                                aria-label={`${user.status === 'enabled' ? 'Disable' : 'Enable'} ${user.name}${user.email ? ` (${user.email})` : ''}`}
                                                onClick={() => { setNotice(''); setChangeError(''); setSelected(user); }}>
                                                {user.status === 'enabled' ? 'Disable' : 'Enable'}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {response?.meta.last_page > 1 && (
                <MoviePagination page={response.meta.current_page} totalPages={response.meta.last_page}
                    label="User management pagination" loading={!!selected}
                    onPageChange={(page) => { setNotice(''); setRequest({ ...request, page }); }} />
            )}
        </main>
    );
}
