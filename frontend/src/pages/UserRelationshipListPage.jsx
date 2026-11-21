import { useEffect, useRef, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import MoviePagination from '../components/MoviePagination';
import { getPublicProfile, getUserRelationshipList, setUserFollow } from '../services/profile';
import { useAuth } from '../hooks/useAuth';

const labels = {
    followers: 'Followers',
    following: 'Following',
};

export default function UserRelationshipListPage({ relationship }) {
    const { userId } = useParams();
    return <RelationshipList key={`${userId}:${relationship}`} userId={userId} relationship={relationship} />;
}

function RelationshipList({ userId, relationship }) {
    const { user, isAuthenticated, loading: authLoading } = useAuth();
    const [page, setPage] = useState(1);
    const [attempt, setAttempt] = useState(0);
    const [profile, setProfile] = useState(null);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [missing, setMissing] = useState(false);
    const [error, setError] = useState('');
    const [savingId, setSavingId] = useState(null);
    const [followError, setFollowError] = useState('');
    const mutation = useRef(null);
    const label = labels[relationship];

    useEffect(() => () => mutation.current?.abort(), []);

    useEffect(() => {
        const controller = new AbortController();

        Promise.all([
            getPublicProfile(userId, { signal: controller.signal }),
            getUserRelationshipList(userId, relationship, page, { signal: controller.signal }),
        ]).then(([nextProfile, nextResult]) => {
            if (controller.signal.aborted) return;
            if (nextResult.data.length === 0 && page > nextResult.meta.last_page) {
                setPage(Math.max(1, nextResult.meta.last_page));
                return;
            }
            setProfile(nextProfile);
            setResult(nextResult);
            setMissing(false);
            setLoading(false);
        }).catch((requestError) => {
            if (controller.signal.aborted) return;
            setMissing(requestError.response?.status === 404);
            setError(requestError.response?.status === 404 ? '' : `Could not load ${label.toLowerCase()}. Please try again.`);
            setLoading(false);
        });

        return () => controller.abort();
    }, [userId, relationship, page, attempt, label]);

    function retry(nextPage = page) {
        setLoading(true);
        setResult(null);
        setError('');
        setFollowError('');
        setPage(nextPage);
        setAttempt((value) => value + 1);
    }

    async function toggleFollow(listedUser) {
        if (!isAuthenticated || savingId || String(user?.id) === listedUser.id) return;
        const controller = new AbortController();
        mutation.current = controller;
        setSavingId(listedUser.id);
        setFollowError('');
        try {
            const updated = await setUserFollow(listedUser.id, !listedUser.is_following, { signal: controller.signal });
            if (controller.signal.aborted) return;
            setResult((previous) => previous && {
                ...previous,
                data: previous.data.map((entry) => entry.id === listedUser.id ? { ...entry, ...updated } : entry),
            });
        } catch (requestError) {
            if (controller.signal.aborted) return;
            setFollowError(requestError.response?.status === 401
                ? 'Your session expired. Please log in to follow users.'
                : 'Could not update follow status. Please try again.');
        } finally {
            if (!controller.signal.aborted) setSavingId(null);
        }
    }

    return (
        <main className="container py-4 py-md-5 text-start" aria-labelledby="relationship-heading">
            <Link className="d-inline-block mb-3" to={`/users/${encodeURIComponent(userId)}`}>Back to public profile</Link>
            {loading && <p role="status">Loading {label.toLowerCase()}…</p>}
            {missing && <div className="alert alert-warning" role="alert">Profile not found.</div>}
            {error && <div className="alert alert-danger" role="alert">
                <p className="mb-3">{error}</p>
                <button type="button" className="btn btn-outline-danger" onClick={() => retry()}>Try again</button>
            </div>}
            {followError && <p className="alert alert-danger" role="alert">{followError}</p>}
            {!loading && !missing && !error && profile && result && <>
                <header className="mb-4">
                    <h1 id="relationship-heading" className="h2 text-break">{label}</h1>
                    <p className="text-body-secondary mb-0">{profile.name}</p>
                </header>
                {result.data.length === 0 ? (
                    <p className="alert alert-info" role="status">No {label.toLowerCase()} yet.</p>
                ) : (
                    <div className="row g-3">
                        {result.data.map((listedUser) => {
                            const ownCard = String(user?.id) === listedUser.id;
                            return <div key={listedUser.id} className="col-12 col-sm-6 col-lg-4">
                                <article className="card h-100" aria-label={listedUser.name}>
                                    <div className="card-body d-flex flex-column gap-2">
                                        <h2 className="h5 text-break mb-0">
                                            <Link to={`/users/${encodeURIComponent(listedUser.id)}`}>{listedUser.name}</Link>
                                        </h2>
                                        <p className="small text-body-secondary mb-2">
                                            {listedUser.followers_count} followers · {listedUser.following_count} following
                                        </p>
                                        {isAuthenticated && !authLoading && !ownCard && <button type="button"
                                            className="btn btn-outline-primary mt-auto" disabled={savingId !== null}
                                            aria-pressed={listedUser.is_following}
                                            onClick={() => toggleFollow(listedUser)}>
                                            {savingId === listedUser.id ? 'Updating…' : listedUser.is_following ? 'Unfollow' : 'Follow'}
                                        </button>}
                                    </div>
                                </article>
                            </div>;
                        })}
                    </div>
                )}
                {result.meta.last_page > 1 && <MoviePagination page={result.meta.current_page}
                    totalPages={result.meta.last_page} loading={savingId !== null}
                    onPageChange={retry} label={`${label} pagination`} />}
            </>}
        </main>
    );
}
