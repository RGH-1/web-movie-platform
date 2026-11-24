import { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import AdminNavigation from '../components/AdminNavigation';
import { getAdminDashboard } from '../services/adminDashboard';

const count = new Intl.NumberFormat();
const rating = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 });
const dateTime = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const activityLabels = {
    review_created: 'Review created',
    movie_watched: 'Marked as watched',
};

export default function AdminDashboardPage() {
    const location = useLocation();
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);

    useEffect(() => {
        const controller = new AbortController();

        getAdminDashboard({ signal: controller.signal })
            .then((data) => {
                if (!controller.signal.aborted) setResult({ attempt, data });
            })
            .catch((error) => {
                if (!controller.signal.aborted) setResult({ attempt, error });
            });

        return () => controller.abort();
    }, [attempt]);

    const current = result?.attempt === attempt ? result : null;
    const status = current?.error?.response?.status;

    if (status === 401) {
        return <Navigate to="/login" replace state={{ from: location }} />;
    }

    return (
        <main className="container py-4 py-md-5 text-start">
            <AdminNavigation />
            <h1 className="h2 my-3">Admin dashboard</h1>
            {!current && <p role="status">Loading dashboard…</p>}
            {status === 403 && (
                <p className="alert alert-warning" role="alert">Administrator access is required.</p>
            )}
            {current?.error && status !== 403 && (
                <>
                    <p className="alert alert-danger" role="alert">Could not load dashboard metrics.</p>
                    <button type="button" className="btn btn-outline-primary" onClick={() => setAttempt(attempt + 1)}>
                        Try again
                    </button>
                </>
            )}
            {current?.data && <DashboardMetrics data={current.data} />}
        </main>
    );
}

function DashboardMetrics({ data }) {
    return (
        <>
            <div className="row row-cols-1 row-cols-sm-2 g-3 mb-4">
                {[
                    ['Total users', data.kpis.total_users],
                    ['Total reviews', data.kpis.total_reviews],
                ].map(([label, value]) => (
                    <div className="col" key={label}>
                        <dl className="card card-body h-100 mb-0">
                            <dt className="text-body-secondary">{label}</dt>
                            <dd className="fs-2 fw-semibold mb-0">{count.format(value)}</dd>
                        </dl>
                    </div>
                ))}
            </div>
            <div className="row g-4">
                <DashboardTable title="Most watched movies" id="most-watched" width="col-lg-6"
                    columns={['Movie', 'Viewers']}
                    rows={data.most_watched_movies.map((movie) => ({
                        key: movie.movie_id,
                        cells: [movie.title, count.format(movie.watch_count)],
                    }))}
                    empty="No movies have been marked as watched."
                />
                <DashboardTable title="Highest rated movies" id="highest-rated" width="col-lg-6"
                    columns={['Movie', 'Average rating', 'Visible reviews']}
                    rows={data.highest_rated_movies.map((movie) => ({
                        key: movie.movie_id,
                        cells: [movie.title, rating.format(movie.average_rating), count.format(movie.review_count)],
                    }))}
                    empty="No movies have visible reviews."
                />
                <DashboardTable title="Popular genres" id="popular-genres" width="col-lg-4"
                    columns={['Genre', 'Engagements']}
                    rows={data.popular_genres.map((genre) => ({
                        key: genre.genre_id ?? genre.genre_name,
                        cells: [genre.genre_name, count.format(genre.engagement_count)],
                    }))}
                    empty="No genre engagement has been recorded."
                />
                <DashboardTable title="Recent activity" id="recent-activity" width="col-lg-8"
                    columns={['User', 'Activity', 'Movie', 'Date and time']}
                    rows={data.recent_activity.map((event) => ({
                        key: `${event.event_type}-${event.user_id}-${event.movie_id}-${event.occurred_at}`,
                        cells: [
                            event.user_name,
                            activityLabels[event.event_type] ?? event.event_type,
                            event.movie_title,
                            <time key="occurred_at" dateTime={event.occurred_at}>
                                {dateTime.format(new Date(event.occurred_at))}
                            </time>,
                        ],
                    }))}
                    empty="No recent activity."
                />
            </div>
        </>
    );
}

function DashboardTable({ title, id, width, columns, rows, empty }) {
    return (
        <section className={`col-12 ${width}`} aria-labelledby={id}>
            <div className="card h-100">
                <div className="card-body">
                    <h2 className="h5 mb-3" id={id}>{title}</h2>
                    {rows.length === 0 ? (
                        <p className="text-body-secondary mb-0">{empty}</p>
                    ) : (
                        <div className="table-responsive" tabIndex={0} role="region" aria-labelledby={id}>
                            <table className="table table-sm align-middle mb-0" aria-labelledby={id}>
                                <thead>
                                    <tr>{columns.map((column) => <th key={column} scope="col">{column}</th>)}</tr>
                                </thead>
                                <tbody>
                                    {rows.map(({ key, cells }) => (
                                        <tr key={key}>
                                            {cells.map((cell, index) => <td key={columns[index]}>{cell}</td>)}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}
