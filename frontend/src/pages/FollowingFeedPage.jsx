import { useEffect, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import MoviePagination from '../components/MoviePagination';
import api from '../services/api';

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
            <ul className="list-group">
              {result.items.map(({ type, target_id, author, timestamp, metadata }) => (
                <li key={type + '-' + target_id} className="list-group-item">
                  <Link to={'/users/' + author.id}>{author.name}</Link>{' '}
                  {type === 'public_list' ? (
                    <>published <Link to={'/lists/' + target_id}>{metadata.name}</Link></>
                  ) : (
                    <>
                      {type === 'review' ? 'reviewed' : 'rated'}{' '}
                      <Link to={'/movies/local/' + metadata.movie_id}>{metadata.title}</Link>
                      {' '}({metadata.rating}/5)
                    </>
                  )}
                  <time className="d-block text-body-secondary small" dateTime={timestamp}>
                    {new Date(timestamp).toLocaleString()}
                  </time>
                </li>
              ))}
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
