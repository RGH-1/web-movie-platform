import { useEffect, useRef, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { useAuth } from "../hooks/useAuth.js";
import { getMovieStatus, updateMovieStatus } from "../services/movieStatus.js";

export default function MovieStatusActions({ movie }) {
    const { user, loading, profileCompletion } = useAuth();
    const location = useLocation();

    return (
        <MovieStatusContent
            key={`${user?.id ?? "guest"}:${movie?.local_movie_id ?? "no-id"}`}
            movie={movie}
            user={user}
            loading={loading}
            profileCompletion={profileCompletion}
            location={location}
        />
    );
}

function MovieStatusContent({
    movie,
    user,
    loading,
    profileCompletion,
    location,
}) {
    const movieId = movie?.local_movie_id;
    const validMovieId =
        typeof movieId === "string" && /^[1-9]\d*$/.test(movieId);

    const [status, setStatus] = useState(null);
    const [loadingStatus, setLoadingStatus] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [reloadCount, setReloadCount] = useState(0);

    const busyRef = useRef(false);

    const canLoad =
        !loading &&
        user !== null &&
        profileCompletion?.complete !== false &&
        validMovieId;

    useEffect(() => {
        if (!canLoad) {
            return;
        }

        let active = true;
        const controller = new AbortController();

        getMovieStatus(movieId, { signal: controller.signal })
            .then((data) => {
                if (active) {
                    setStatus(data);
                }
            })
            .catch((err) => {
                if (!active) return;

                setError(
                    err.response?.status === 404
                        ? "This movie is no longer available."
                        : "Could not load your movie status.",
                );
            })
            .finally(() => {
                if (active) {
                    setLoadingStatus(false);
                }
            });

        return () => {
            active = false;
            controller.abort();
        };
    }, [canLoad, movieId, user?.id, reloadCount]);

    async function changeStatus(field, value) {
        if (busyRef.current || !status || !canLoad) {
            return;
        }

        busyRef.current = true;
        setSaving(true);
        setError("");

        try {
            const updated = await updateMovieStatus(movieId, {
                [field]: value,
            });

            setStatus(updated);
        } catch (err) {
            const message =
                err.response?.status === 403
                    ? "Please complete your profile first."
                    : err.response?.status === 401
                      ? "Please sign in again."
                      : "Unable to update your movie status. Please try again.";

            setError(message);
        } finally {
            busyRef.current = false;
            setSaving(false);
        }
    }

    let content;

    if (loading) {
        content = <p role="status">Checking authentication...</p>;
    } else if (!user) {
        content = (
            <Link to="/login" state={{ from: location }}>
                Sign in to manage your watchlist and watched history
            </Link>
        );
    } else if (profileCompletion?.complete === false) {
        content = (
            <Link to="/profile?edit=1">
                Complete your profile to manage movie statuses
            </Link>
        );
    } else if (!validMovieId) {
        content = (
            <p className="text-body-secondary mb-0">
                Watchlist and watched controls are unavailable for this movie.
            </p>
        );
    } else if (loadingStatus || (!status && !error)) {
        content = <p role="status">Loading your movie status...</p>;
    } else if (!status) {
        content = (
            <div>
                <p role="alert" className="text-danger">
                    {error}
                </p>
                <button
                    type="button"
                    className="btn btn-outline-primary"
                    onClick={() => {
                        setError("");
                        setLoadingStatus(true);
                        setReloadCount((count) => count + 1);
                    }}
                >
                    Try again
                </button>
            </div>
        );
    } else {
        content = (
            <>
                <div className="d-flex flex-wrap gap-2">
                    <button
                        type="button"
                        className={
                            status.in_watchlist
                                ? "btn btn-success"
                                : "btn btn-outline-primary"
                        }
                        aria-pressed={status.in_watchlist}
                        disabled={saving}
                        onClick={() =>
                            changeStatus("in_watchlist", !status.in_watchlist)
                        }
                    >
                        {status.in_watchlist
                            ? "Remove from Watchlist"
                            : "Add to Watchlist"}
                    </button>

                    <button
                        type="button"
                        className={
                            status.is_watched
                                ? "btn btn-success"
                                : "btn btn-outline-secondary"
                        }
                        aria-pressed={status.is_watched}
                        disabled={saving}
                        onClick={() =>
                            changeStatus("is_watched", !status.is_watched)
                        }
                    >
                        {status.is_watched
                            ? "Mark as Unwatched"
                            : "Mark as Watched"}
                    </button>
                </div>

                {saving && (
                    <p role="status" className="small mt-2 mb-0">
                        Saving changes...
                    </p>
                )}

                {error && (
                    <p role="alert" className="text-danger mt-2 mb-0">
                        {error}
                    </p>
                )}
            </>
        );
    }

    return (
        <section className="card mt-4" aria-label="Movie status">
            <div className="card-body p-3 p-md-4">
                <h2 className="h4 mb-3">Your movie status</h2>
                {content}
            </div>
        </section>
    );
}
