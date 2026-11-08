import { useEffect, useState } from 'react';
import { Link, Navigate, useLocation, useSearchParams } from 'react-router-dom';
import { getProfile } from '../services/profile';
import ProfileEditForm from '../components/ProfileEditForm';
import { useAuth } from '../hooks/useAuth';
import { GENRES } from '../utils/profileForm';
import ProfileCompletionNotice from '../components/ProfileCompletionNotice';



export default function ProfilePage() {
  const location = useLocation();
  const { setUser, profileCompletion } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const [editRequested, setEditRequested] = useState(false);
  const editing = editRequested || searchParams.get('edit') === '1';

  function setEditing(value) {
    setEditRequested(value);
    if (!value && searchParams.has('edit')) {
      const next = new URLSearchParams(searchParams);
      next.delete('edit');
      setSearchParams(next, { replace: true });
    }
  }
  const [success, setSuccess] = useState('');
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

  function saved(profile) {
    setState({ status: 'ready', profile });
    setUser((current) => current ? { ...current, name: profile.name, email: profile.email } : current);
    setEditing(false);
    setSuccess('Your profile was saved.');
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
            <ProfileCompletionNotice
              completion={profileCompletion}
              showAction={!editing}
              onEdit={editing ? undefined : () => { setSuccess(''); setEditing(true); }}
            />
          )}

          {success && <p className="alert alert-success" role="status">{success}</p>}

          {state.status === 'ready' && editing && (
            <div className="card shadow-sm">
              <div className="card-body p-3 p-md-4">
                <ProfileEditForm
                  profile={state.profile}
                  onSaved={saved}
                  onCancel={() => setEditing(false)}
                  onUnauthenticated={() => setState({ status: 'unauthenticated', profile: null })}
                />
              </div>
            </div>
          )}

          {state.status === 'ready' && !editing && (
            <article className="card shadow-sm">
              <div className="card-body p-3 p-md-4">
                <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                  <h2 className="h5 mb-0">Account details</h2>
                  <button type="button" className="btn btn-outline-primary"
                    onClick={() => { setSuccess(''); setEditing(true); }}>
                    Edit profile
                  </button>
                </div>
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
                              {GENRES[genre] ?? genre}
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
