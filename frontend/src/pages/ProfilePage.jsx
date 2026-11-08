import { useEffect, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import { getProfile } from '../services/profile';

const genreLabels = {
  action: 'Action',
  adventure: 'Adventure',
  animation: 'Animation',
  comedy: 'Comedy',
  crime: 'Crime',
  documentary: 'Documentary',
  drama: 'Drama',
  fantasy: 'Fantasy',
  horror: 'Horror',
  mystery: 'Mystery',
  romance: 'Romance',
  'science-fiction': 'Science fiction',
  thriller: 'Thriller',
};

export default function ProfilePage() {
  const location = useLocation();
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState({ status: 'loading', profile: null });

  useEffect(() => {
    const controller = new AbortController();

    async function loadProfile() {
      try {
        const profile = await getProfile({ signal: controller.signal });
        if (!controller.signal.aborted) {
          setState({ status: profile ? 'ready' : 'empty', profile });
        }
      } catch (error) {
        if (!controller.signal.aborted) {
          setState({
            status: error.response?.status === 401 ? 'unauthenticated' : 'error',
            profile: null,
          });
        }
      }
    }

    loadProfile();
    return () => controller.abort();
  }, [attempt]);

  if (state.status === 'unauthenticated') {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  function retry() {
    setState({ status: 'loading', profile: null });
    setAttempt((value) => value + 1);
  }

  return (
    <main className="container py-4 py-md-5 text-start" aria-labelledby="profile-heading">
      <div className="row justify-content-center">
        <div className="col-12 col-lg-9">
          <Link to="/" className="d-inline-block mb-3">Back to home</Link>
          <h1 id="profile-heading" className="h2 mb-2">My profile</h1>
          <p className="text-body-secondary mb-4">
            Your account information and movie preferences.
          </p>

          {state.status === 'loading' && (
            <div role="status" className="d-flex align-items-center gap-2 py-4">
              <span className="spinner-border spinner-border-sm" aria-hidden="true" />
              Loading your profile…
            </div>
          )}

          {state.status === 'error' && (
            <div className="alert alert-danger" role="alert">
              <p className="mb-3">We couldn’t load your profile. Please try again.</p>
              <button type="button" className="btn btn-outline-danger" onClick={retry}>
                Try again
              </button>
            </div>
          )}

          {state.status === 'empty' && (
            <div className="alert alert-info" role="status">
              <p className="mb-3">No profile information was returned.</p>
              <button type="button" className="btn btn-outline-primary" onClick={retry}>
                Try again
              </button>
            </div>
          )}

          {state.status === 'ready' && (
            <article className="card shadow-sm">
              <div className="card-body p-3 p-md-4">
                <h2 className="h5 mb-4">Account details</h2>
                <dl className="row mb-0">
                  <dt className="col-12 col-sm-4 mb-1">Name</dt>
                  <dd className="col-12 col-sm-8 mb-4 text-break">
                    {state.profile.name.trim() || 'Not provided'}
                  </dd>

                  <dt className="col-12 col-sm-4 mb-1">Email</dt>
                  <dd className="col-12 col-sm-8 mb-4 text-break">
                    {state.profile.email || 'Not provided'}
                  </dd>

                  <dt className="col-12 col-sm-4 mb-1">Bio</dt>
                  <dd className="col-12 col-sm-8 mb-4 text-break"
                    style={{ whiteSpace: 'pre-wrap' }}>
                    {state.profile.bio || 'No bio added yet.'}
                  </dd>

                  <dt className="col-12 col-sm-4 mb-1">Favorite genres</dt>
                  <dd className="col-12 col-sm-8 mb-0">
                    {state.profile.favorite_genres.length === 0 ? (
                      'No favorite genres selected yet.'
                    ) : (
                      <ul className="list-unstyled d-flex flex-wrap gap-2 mb-0">
                        {state.profile.favorite_genres.map((genre, index) => (
                          <li key={genre + index}>
                            <span className="badge text-bg-secondary">
                              {genreLabels[genre] ?? genre}
                            </span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </dd>
                </dl>
              </div>
            </article>
          )}
        </div>
      </div>
    </main>
  );
}
