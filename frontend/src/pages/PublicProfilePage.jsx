import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { getPublicProfile, setUserFollow } from '../services/profile.js';
import { useAuth } from '../hooks/useAuth.js';
import ReviewSentiment from '../components/ReviewSentiment.jsx';

export default function PublicProfilePage() {
    const { userId } = useParams();

    return <PublicProfile key={userId} userId={userId} />;
}

function PublicProfile({ userId }) {
    const { user, isAuthenticated, loading } = useAuth();
    return <ProfileContent key={`${userId}:${user?.id ?? 'guest'}`} userId={userId}
        ownProfile={String(user?.id) === userId} authenticated={isAuthenticated} authLoading={loading} />;
}

function ProfileContent({ userId, ownProfile, authenticated, authLoading }) {
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);
    const [saving, setSaving] = useState(false);
    const [followError, setFollowError] = useState('');

    useEffect(() => {
        const controller = new AbortController();

        getPublicProfile(userId, { signal: controller.signal })
            .then((profile) => {
                if (!controller.signal.aborted) setResult({ attempt, profile });
            })
            .catch((error) => {
                if (controller.signal.aborted) return;

                const missing = error.response?.status === 404;

                setResult((previous) => (
                    !missing && previous?.profile
                        ? {
                            attempt,
                            profile: previous.profile,
                            refreshError: true,
                        }
                        : { attempt, missing }
                ));
            });

        return () => controller.abort();
    }, [userId, attempt]);

    const current = result?.profile
        ? result
        : result?.attempt === attempt ? result : null;

    const profile = current?.profile;
    const refreshing = Boolean(
        current?.profile && current.attempt !== attempt,
    );

    async function toggleFollow() {
        if (saving || refreshing || !authenticated || ownProfile) return;
        setSaving(true);
        setFollowError('');
        try {
            const updated = await setUserFollow(userId, !profile.is_following);
            setResult((previous) => ({ ...previous, profile: { ...previous.profile, ...updated } }));
        } catch (error) {
            setFollowError(error.response?.status === 401
                ? 'Please log in to follow this user.'
                : 'Could not update follow status. Please try again.');
        } finally {
            setSaving(false);
        }
    }

    return (
        <main className="container py-5 text-start">
            {current?.profile ? (
                <>
                    <header className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h1 className="h2 text-break">{profile.name}</h1>
                            <p className="mb-0" aria-live="polite">
                                <Link to={`/users/${encodeURIComponent(userId)}/followers`}>
                                    {profile.followers_count} followers
                                </Link>
                                {' · '}
                                <Link to={`/users/${encodeURIComponent(userId)}/following`}>
                                    {profile.following_count} following
                                </Link>
                            </p>
                        </div>
                        {!ownProfile && !authLoading && (authenticated ? (
                            <button type="button" className="btn btn-primary" disabled={saving || refreshing}
                                aria-pressed={profile.is_following} onClick={toggleFollow}>
                                {saving ? 'Updating…' : profile.is_following ? 'Unfollow' : 'Follow'}
                            </button>
                        ) : (
                            <Link className="btn btn-outline-primary" to="/login"
                                state={{ from: { pathname: `/users/${userId}` } }}>Log in to follow</Link>
                        ))}
                    </header>
                    {followError && <p role="alert" className="text-danger">{followError}</p>}
                    {refreshing && <p role="status">Refreshing public reviews.</p>}

                    {current.refreshError && (
                        <p role="alert" className="text-danger">
                            Could not refresh public reviews. Showing the last loaded profile.
                        </p>
                    )}
                    <div className="row g-4">
                        <section className="col-12 col-lg-7" aria-labelledby="public-reviews-heading">
                            <div className="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                                <h2 id="public-reviews-heading" className="h4">
                                    Public reviews ({profile.statistics?.reviews_count ?? profile.reviews.length})
                                </h2>

                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-primary"
                                    disabled={saving || refreshing}
                                    onClick={() => setAttempt((value) => value + 1)}
                                >
                                    Refresh public reviews
                                </button>
                            </div>

                            <p className="small text-body-secondary">
                                Text sentiment is automated analysis of written reviews,
                                separate from user star ratings.
                            </p>
                            {profile.reviews.length ? <div className="d-grid gap-3">
                                {profile.reviews.map((review) => <article key={review.id} className="border rounded p-3">
                                    <div className="d-flex flex-wrap justify-content-between gap-2">
                                        <Link to={`/movies/local/${encodeURIComponent(review.movie_id)}`}>View movie</Link>
                                        <strong>{review.rating} / 5</strong>
                                    </div>
                                    <p className="text-break mt-2 mb-0" style={{ whiteSpace: 'pre-wrap' }}>{review.review_text || 'Rating only'}</p>
                                    <ReviewSentiment review={review} />
                                </article>)}
                            </div> : <p>No public reviews yet.</p>}
                        </section>
                        <section className="col-12 col-lg-5" aria-labelledby="public-lists-heading">
                            <h2 id="public-lists-heading" className="h4">Public lists ({profile.statistics?.public_lists_count ?? profile.public_lists.length})</h2>
                            {!authenticated && !authLoading && profile.public_lists.length > 0 && (
                                <p id="public-list-sign-in" className="small text-body-secondary">
                                    Sign in to open a movie list.
                                </p>
                            )}
                            {profile.public_lists.length ? <div className="d-grid gap-3">
                                {profile.public_lists.map((list) => <article key={list.id} className="border rounded p-3">
                                    <Link className="text-break" to={`/lists/${encodeURIComponent(list.id)}`}
                                        aria-describedby={!authenticated && !authLoading ? 'public-list-sign-in' : undefined}>
                                        {list.name}
                                    </Link>
                                    {list.description && <p className="text-break mt-2">{list.description}</p>}
                                    <p className="small mb-0">{list.movies_count} movies</p>
                                </article>)}
                            </div> : <p>No public lists yet.</p>}
                        </section>
                    </div>
                </>
            ) : (
                <>
                    <h1 className="h2">Public profile</h1>
                    {!current && <p role="status">Loading profile…</p>}
                    {current?.missing && <p role="alert">Profile not found.</p>}
                    {current && !current.missing && (
                        <>
                            <p role="alert">Could not load this profile.</p>
                            <button
                                type="button"
                                className="btn btn-outline-primary"
                                onClick={() => setAttempt((value) => value + 1)}
                            >
                                Try again
                            </button>
                        </>
                    )}
                </>
            )}

            <Link className="d-inline-block mt-3" to="/movies">Discover movies</Link>
        </main>
    );
}
