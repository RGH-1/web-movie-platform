import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import MovieNightError from '../components/MovieNightError';
import MovieNightForm from '../components/MovieNightForm';
import MoviePagination from '../components/MoviePagination';
import { getMovieNights } from '../services/movieNights';
import { formatMovieNightStart, movieNightStatus } from '../utils/movieNights';

export default function MyMovieNightsPage() {
    const navigate = useNavigate();
    const [creating, setCreating] = useState(false);
    const [request, setRequest] = useState({ page: 1 });
    const [result, setResult] = useState(null);

    useEffect(() => {
        const controller = new AbortController();
        getMovieNights(request.page, { signal: controller.signal }).then((response) => {
            if (controller.signal.aborted) return;
            if (request.page > response.meta.last_page) {
                setRequest({ page: response.meta.last_page });
                return;
            }
            setResult({ request, response });
        }).catch((error) => {
            if (!controller.signal.aborted) setResult({ request, error });
        });
        return () => controller.abort();
    }, [request]);

    const current = result?.request === request ? result : null;
    const nights = current?.response?.data;
    const meta = current?.response?.meta;

    return (
        <main className="container py-4 py-md-5 text-start">
            <Link to="/">Back to home</Link>
            <header className="d-flex flex-wrap justify-content-between align-items-center gap-3 my-3">
                <h1 className="h2 mb-0">My Movie Nights</h1>
                {nights && !creating && (
                    <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>Plan a movie night</button>
                )}
            </header>

            {!current && <p role="status">Loading movie nights…</p>}
            {current?.error && <MovieNightError error={current.error} message="Could not load your movie nights."
                onRetry={() => setRequest({ page: request.page })} />}
            {creating && <MovieNightForm onCancel={() => setCreating(false)}
                onSaved={(night) => navigate(`/movie-nights/${night.id}`, { state: { movieNightCreated: true } })} />}
            {nights?.length === 0 && <p role="status">You don't have any movie nights yet.</p>}
            {nights?.length > 0 && (
                <div className="row g-3">
                    {nights.map((night) => (
                        <div className="col-12 col-md-6 col-xl-4" key={night.id}>
                            <article className="card h-100">
                                <div className="card-body">
                                    <h2 className="h5 text-break"><Link to={`/movie-nights/${night.id}`}>{night.title}</Link></h2>
                                    <p className="text-body-secondary">{movieNightStatus[night.status]} · {night.viewer_role === 'organizer' ? 'Organizing' : 'Participating'}</p>
                                    <p className="text-break mb-2">Organizer: {night.organizer.name}</p>
                                    <time dateTime={night.starts_at}>{formatMovieNightStart(night)} ({night.timezone})</time>
                                    {night.location && <p className="text-break mt-2 mb-0">{night.location}</p>}
                                </div>
                            </article>
                        </div>
                    ))}
                </div>
            )}
            {meta?.last_page > 1 && <MoviePagination page={meta.current_page} totalPages={meta.last_page}
                loading={creating} onPageChange={(page) => setRequest({ page })} label="Movie night pagination" />}
        </main>
    );
}
