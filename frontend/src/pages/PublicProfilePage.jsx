import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { getPublicProfile } from '../services/profile.js';

export default function PublicProfilePage() {
    const { userId } = useParams();

    return <PublicProfile key={userId} userId={userId} />;
}

function PublicProfile({ userId }) {
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);

    useEffect(() => {
        const controller = new AbortController();

        getPublicProfile(userId, { signal: controller.signal })
            .then((profile) => {
                if (!controller.signal.aborted) setResult({ attempt, profile });
            })
            .catch((error) => {
                if (!controller.signal.aborted) {
                    setResult({ attempt, missing: error.response?.status === 404 });
                }
            });

        return () => controller.abort();
    }, [userId, attempt]);

    const current = result?.attempt === attempt ? result : null;

    return (
        <main className="container py-5 text-start">
            {current?.profile ? (
                <h1 className="h2 text-break">{current.profile.name}</h1>
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