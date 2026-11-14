import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";

import MovieCard from "../components/MovieCard.jsx";
import { useAuth } from "../hooks/useAuth.js";
import { getMyMovies, updateMovieStatus } from "../services/movieStatus.js";

function formatWatchedDate(value) {
    if (!value) return null;

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return "Date unavailable";
    }

    return new Intl.DateTimeFormat(undefined, {
        year: "numeric",
        month: "long",
        day: "numeric",
    }).format(date);
}

function MyMoviesCollection({ filter, page, onPageChange, onRefresh }) {
    const [result, setResult] = useState(null);
    const [error, setError] = useState("");
    const [actionError, setActionError] = useState("");
    const [savingMovieId, setSavingMovieId] = useState(null);

    const busyRef = useRef(false);

    const mountedRef = useRef(false);

    useEffect(() => {
        mountedRef.current = true;

        return () => {
            mountedRef.current = false;
        };
    }, []);

    useEffect(() => {
        let active = true;

        getMyMovies(filter, page, 12)
            .then((response) => {
                if (!active) return;

                if (!Array.isArray(response?.data) || !response?.meta) {
                    throw new Error("Invalid collection response.");
                }

                setResult(response);
            })
            .catch((err) => {
                if (!active) return;

                if (err.response?.status === 403) {
                    setError("PROFILE_INCOMPLETE");
                } else {
                    setError("Could not load your movies. Please try again.");
                }
            });

        return () => {
            active = false;
        };
    }, [filter, page]);

    async function handleStatusChange(entry, field, value) {
        if (busyRef.current) return;

        busyRef.current = true;
        setSavingMovieId(entry.movie_id);
        setActionError("");

        try {
            const updated = await updateMovieStatus(entry.movie_id, {
                [field]: value,
            });
            if (!mountedRef.current) return;
            const stillInCollection =
                filter === "watchlist"
                    ? updated.in_watchlist
                    : updated.is_watched;

            if (!stillInCollection && result.data.length === 1 && page > 1) {
                onPageChange(page - 1);
            } else {
                onRefresh();
            }
        } catch (err) {
            if (!mountedRef.current) return;
            if (err.response?.status === 403) {
                setActionError(
                    "Please complete your profile before updating movies.",
                );
            } else if (err.response?.status === 401) {
                setActionError("Please sign in again.");
            } else {
                setActionError(
                    "Could not update the movie. Your saved status has not been changed on this page.",
                );
            }
        } finally {
            busyRef.current = false;

            if (mountedRef.current) {
                setSavingMovieId(null);
            }
        }
    }

    if (error) {
        return (
            <div className="alert alert-danger" role="alert">
                {error === "PROFILE_INCOMPLETE" ? (
                    <>
                        Please complete your profile.{" "}
                        <Link to="/profile?edit=1">Complete profile</Link>
                    </>
                ) : (
                    <>
                        <p>{error}</p>
                        <button
                            type="button"
                            className="btn btn-outline-danger"
                            onClick={onRefresh}
                        >
                            Try again
                        </button>
                    </>
                )}
            </div>
        );
    }

    if (!result) {
        return (
            <p role="status">
                Loading your{" "}
                {filter === "watchlist" ? "watchlist" : "watched history"}...
            </p>
        );
    }

    const entries = result.data;
    const meta = result.meta;
    const lastPage = Math.max(1, Number(meta.last_page) || 1);

    return (
        <div>
            <p className="text-body-secondary">
                {meta.total} {meta.total === 1 ? "movie" : "movies"}
            </p>

            {actionError && (
                <div className="alert alert-danger" role="alert">
                    {actionError}
                </div>
            )}

            {entries.length === 0 ? (
                <div className="text-center py-5">
                    <h2 className="h4">
                        {filter === "watchlist"
                            ? "Your watchlist is empty"
                            : "No watched movies yet"}
                    </h2>

                    <p className="text-body-secondary">
                        {filter === "watchlist"
                            ? "Discover movies and add them to your watchlist."
                            : "Mark movies as watched to see them here."}
                    </p>

                    <Link to="/movies" className="btn btn-primary">
                        Discover Movies
                    </Link>
                </div>
            ) : (
                <div className="row g-4">
                    {entries.map((entry) => {
                        const saving = savingMovieId === entry.movie_id;

                        return (
                            <div
                                key={entry.movie_id}
                                className="col-12 col-sm-6 col-md-4 col-lg-3"
                            >
                                <div className="d-flex flex-column gap-2 h-100">
                                    <MovieCard movie={entry.movie} />

                                    <div className="card">
                                        <div className="card-body">
                                            {entry.is_watched &&
                                                entry.watched_at && (
                                                    <p className="small text-body-secondary">
                                                        Watched:{" "}
                                                        {formatWatchedDate(
                                                            entry.watched_at,
                                                        )}
                                                    </p>
                                                )}

                                            <div className="d-grid gap-2">
                                                <button
                                                    type="button"
                                                    className="btn btn-outline-primary btn-sm"
                                                    disabled={
                                                        savingMovieId !== null
                                                    }
                                                    onClick={() =>
                                                        handleStatusChange(
                                                            entry,
                                                            "in_watchlist",
                                                            !entry.in_watchlist,
                                                        )
                                                    }
                                                >
                                                    {entry.in_watchlist
                                                        ? "Remove from Watchlist"
                                                        : "Add to Watchlist"}
                                                </button>

                                                <button
                                                    type="button"
                                                    className="btn btn-outline-secondary btn-sm"
                                                    disabled={
                                                        savingMovieId !== null
                                                    }
                                                    onClick={() =>
                                                        handleStatusChange(
                                                            entry,
                                                            "is_watched",
                                                            !entry.is_watched,
                                                        )
                                                    }
                                                >
                                                    {entry.is_watched
                                                        ? "Mark as Unwatched"
                                                        : "Mark as Watched"}
                                                </button>
                                            </div>

                                            {saving && (
                                                <p
                                                    role="status"
                                                    className="small mt-2 mb-0"
                                                >
                                                    Saving...
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {lastPage > 1 && (
                <nav
                    className="d-flex justify-content-center align-items-center gap-3 mt-5"
                    aria-label="My movies pagination"
                >
                    <button
                        type="button"
                        className="btn btn-outline-primary"
                        disabled={page <= 1 || savingMovieId !== null}
                        onClick={() => onPageChange(page - 1)}
                    >
                        Previous
                    </button>

                    <span>
                        Page {page} of {lastPage}
                    </span>

                    <button
                        type="button"
                        className="btn btn-outline-primary"
                        disabled={page >= lastPage || savingMovieId !== null}
                        onClick={() => onPageChange(page + 1)}
                    >
                        Next
                    </button>
                </nav>
            )}
        </div>
    );
}

export default function MyMoviesPage() {
    const { user } = useAuth();

    const [filter, setFilter] = useState("watchlist");
    const [page, setPage] = useState(1);
    const [refreshCount, setRefreshCount] = useState(0);

    function changeTab(nextFilter) {
        if (nextFilter === filter) return;

        setFilter(nextFilter);
        setPage(1);
    }

    function refreshCollection() {
        setRefreshCount((count) => count + 1);
    }

    return (
        <main className="container py-4 py-md-5">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <h1 className="mb-0">My Movies</h1>

                <Link to="/movies" className="btn btn-outline-primary">
                    Discover Movies
                </Link>
            </div>

            <div
                role="tablist"
                aria-label="Movie collections"
                className="d-flex flex-wrap gap-2 mb-4"
            >
                <button
                    type="button"
                    role="tab"
                    aria-selected={filter === "watchlist"}
                    className={
                        filter === "watchlist"
                            ? "btn btn-primary"
                            : "btn btn-outline-primary"
                    }
                    onClick={() => changeTab("watchlist")}
                >
                    Watchlist
                </button>

                <button
                    type="button"
                    role="tab"
                    aria-selected={filter === "watched"}
                    className={
                        filter === "watched"
                            ? "btn btn-primary"
                            : "btn btn-outline-primary"
                    }
                    onClick={() => changeTab("watched")}
                >
                    Watched History
                </button>
            </div>

            <section
                role="tabpanel"
                aria-label={
                    filter === "watchlist" ? "Watchlist" : "Watched History"
                }
            >
                <MyMoviesCollection
                    key={`${user?.id}:${filter}:${page}:${refreshCount}`}
                    filter={filter}
                    page={page}
                    onPageChange={setPage}
                    onRefresh={refreshCollection}
                />
            </section>
        </main>
    );
}
