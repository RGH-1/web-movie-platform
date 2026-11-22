import { useEffect, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import MoviePagination from '../components/MoviePagination';
import api from '../services/api';

const activityTypes = {
  rating: { label: 'Rating', action: 'rated', border: 'border-primary' },
  review: { label: 'Written review', action: 'reviewed', border: 'border-success' },
  public_list: { label: 'Public list', action: 'published', border: 'border-secondary' },
};

export default function FollowingFeedPage() {
  const location = useLocation();
  const [page, setPage] = useState(1);
  const [attempt, setAttempt] = useState(0);
  const [result, setResult] = useState({ status: 'loading' });

  useEffect(() => {
    const controller = new AbortController();

    async function loadFeed() {
      try {
        const { data } = await api.get('/v1/feed', {
          params: { page },
          signal: controller.signal,
        });
        if (!controller.signal.aborted) {
          setResult({ status: 'ready', items: data.data, meta: data.meta });
        }
      } catch (error) {
        if (!controller.signal.aborted) {
          setResult({ status: error.response?.status === 401 ? 'unauthenticated' : 'error' });
        }
      }
    }

    loadFeed();
    return () => controller.abort();
  }, [page, attempt]);

  if (result.status === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  function changePage(nextPage) {
    setResult({ status: 'loading' });
    setPage(nextPage);
  }

  return (
    <main className="container py-4 text-start">
      <Link to="/">Back to home</Link>
      <h1 className="mt-3">Following feed</h1>
      {result.status === 'loading' && <p role="status">Loading activity…</p>}
      {result.status === 'error' && (
        <div className="alert alert-danger" role="alert">
          <p>Could not load the following feed.</p>
          <button type="button" className="btn btn-outline-danger" onClick={() => {
            setResult({ status: 'loading' });
            setAttempt((current) => current + 1);
          }}>Try again</button>
        </div>
      )}
      {result.status === 'ready' && (
        <>
          {result.items.length === 0 && <p role="status">No activity on this page.</p>}
          {result.items.length > 0 && (
            <ul className="list-unstyled d-grid gap-3 mb-0" role="list">
              {result.items.map(({ type, target_id, author, timestamp, metadata }) => {
                const { label, action, border } = activityTypes[type];
                const isList = type === 'public_list';
                const target = isList ? `/lists/${target_id}` : `/movies/local/${metadata.movie_id}`;

                return (
                  <li key={type + '-' + target_id}>
                    <article className={`card border-2 ${border}`}>
                      <div className="card-body">
                        <header className="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
                          <h2 className="h6 mb-0">{label}</h2>
                          <time className="text-body-secondary small" dateTime={timestamp}>
                            {new Date(timestamp).toLocaleString()}
                          </time>
                        </header>
                        <p className="mb-0 text-break">
                          <Link to={`/users/${author.id}`}>{author.name}</Link>{' '}
                          {action}{' '}
                          <Link to={target}>{isList ? metadata.name : metadata.title}</Link>
                          {!isList && ` (${metadata.rating}/5)`}
                        </p>
                      </div>
                    </article>
                  </li>
                );
              })}
            </ul>
          )}
          {(result.meta.last_page > 1 || page > 1) && (
            <MoviePagination page={page} totalPages={result.meta.last_page}
              onPageChange={changePage} label="Following feed pagination" />
          )}
        </>
      )}
    </main>
  );
}
