import { useEffect, useState } from 'react';
import { Link, Navigate, useLocation, useSearchParams } from 'react-router-dom';
import MoviePagination from '../components/MoviePagination';
import { getPublicLists } from '../services/lists';

export default function PublicListsPage() {
    const location = useLocation();
    const [params, setParams] = useSearchParams();
    const requestedPage = Number(params.get('page'));
    const page = Number.isSafeInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1;
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);

    useEffect(() => {
        const controller = new AbortController();
        getPublicLists(page, { signal: controller.signal }).then((response) => {
            if (!controller.signal.aborted) setResult({ page, attempt, response });
        }).catch((error) => {
            if (!controller.signal.aborted) setResult({ page, attempt, error });
        });
        return () => controller.abort();
    }, [page, attempt]);

    const current = result?.page === page && result.attempt === attempt ? result : null;
    const response = current?.response;

    if (current?.error?.response?.status === 401) {
        return <Navigate to="/login" replace state={{ from: location }} />;
    }

    if (response && page > response.meta.last_page) {
        return <Navigate to={{ search: `?page=${response.meta.last_page}` }} replace />;
    }

    return (
        <main className="container py-4 py-md-5 text-start">
            <Link className="d-inline-block mb-3" to="/">Back to home</Link>
            <h1 className="h2 mb-4">Public movie lists</h1>

            {!current && <p role="status">Loading public lists…</p>}
            {current?.error && (
                <div className="alert alert-danger" role="alert">
                    <p>Could not load public lists. Please try again.</p>
                    <button type="button" className="btn btn-outline-danger" onClick={() => setAttempt(attempt + 1)}>
                        Try again
                    </button>
                </div>
            )}
            {response?.data.length === 0 && <p role="status">No public movie lists yet.</p>}

            {response && (
                <>
                    <div className="row g-3">
                        {response.data.map((list) => (
                            <div className="col-12 col-sm-6 col-lg-4" key={list.id}>
                                <article className="card h-100">
                                    <div className="card-body d-flex flex-column">
                                        <h2 className="h5 text-break">
                                            <Link to={`/lists/${encodeURIComponent(list.id)}`}>{list.name}</Link>
                                        </h2>
                                        <p className="small text-break">
                                            By <Link to={`/users/${encodeURIComponent(list.author.id)}`}>{list.author.name}</Link>
                                        </p>
                                        <p className="text-break" style={{ whiteSpace: 'pre-wrap' }}>
                                            {list.description || 'No description.'}
                                        </p>
                                        <p className="small text-body-secondary mt-auto mb-0">
                                            {list.movie_count} {list.movie_count === 1 ? 'movie' : 'movies'}
                                        </p>
                                    </div>
                                </article>
                            </div>
                        ))}
                    </div>
                    {response.meta.last_page > 1 && (
                        <MoviePagination
                            page={response.meta.current_page}
                            totalPages={response.meta.last_page}
                            onPageChange={(nextPage) => setParams({ page: nextPage })}
                            label="Public lists pagination"
                        />
                    )}
                </>
            )}
        </main>
    );
}
