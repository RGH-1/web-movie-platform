import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import api from '../../src/services/api';
import { AuthProvider } from '../../src/context/AuthContext';
import { useAuth } from '../../src/hooks/useAuth';
import ProtectedRoute from '../../src/components/ProtectedRoute';
import ProfilePage from '../../src/pages/ProfilePage';
import ProfileCompletionNotice from '../../src/components/ProfileCompletionNotice';

const initialProfile = {
  id: 7, name: 'Sam Example', email: 'sam@example.com',
  bio: null, favorite_genres: [],
};
let savedProfile;
let complete;
let patches;
let requests;
let respond;
let originalAdapter;

function response(config, status, data) {
  const result = { config, status, data, headers: {}, statusText: String(status) };
  if (status >= 400) {
    throw new AxiosError('Test HTTP error', 'ERR_BAD_RESPONSE', config, null, result);
  }
  return result;
}

function profileResponse(config) {
  return response(config, 200, {
    data: savedProfile,
    meta: { profile_complete: complete, incomplete_fields: complete ? [] : ['name'] },
  });
}

function LocationProbe() {
  const location = useLocation();
  return <output aria-label="Current location">{location.pathname}{location.search}</output>;
}

function HomeProbe() {
  const { user, profileCompletion } = useAuth();
  return (
    <main>
      <h1>Test home</h1>
      <p>{user?.name ?? 'Guest'}</p>
      <ProfileCompletionNotice completion={profileCompletion} />
      <button type="button" onClick={() => api.get('/v1/movies/search').catch(() => {})}>
        Request restricted feature
      </button>
    </main>
  );
}

function openProfile(path = '/profile') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <AuthProvider>
        <LocationProbe />
        <Routes>
          <Route element={<ProtectedRoute />}>
            <Route path="/profile" element={<ProfilePage />} />
          </Route>
          <Route path="/login" element={<h1>Test login</h1>} />
          <Route path="/" element={<HomeProbe />} />
        </Routes>
      </AuthProvider>
    </MemoryRouter>,
  );
}

async function openEditor(user) {
  await screen.findByRole('button', { name: 'Edit profile' });
  await user.click(screen.getByRole('button', { name: 'Edit profile' }));
  expect(screen.getByRole('form', { name: 'Edit profile' })).toBeInTheDocument();
}

beforeEach(() => {
  savedProfile = structuredClone(initialProfile);
  complete = true;
  patches = [];
  requests = [];
  originalAdapter = api.defaults.adapter;
  respond = async (config) => {
    if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, 204, null);
    if (config.url === '/v1/me/profile' && config.method === 'get') return profileResponse(config);
    if (config.url === '/v1/me/profile' && config.method === 'patch') {
      savedProfile = { ...savedProfile, ...JSON.parse(config.data) };
      delete savedProfile.current_password;
      complete = true;
      return profileResponse(config);
    }
    throw new Error('Unexpected test request: ' + config.method + ' ' + config.url);
  };
  // Exercise actual Axios services/interceptors, but never make a network call.
  api.defaults.adapter = vi.fn(async (config) => {
    requests.push(config);
    if (config.method === 'patch') patches.push(JSON.parse(config.data));
    return respond(config);
  });
});

afterEach(() => {
  api.defaults.adapter = originalAdapter;
});

describe('profile viewing and authentication', () => {
  it('loads only the signed-in profile and safely displays optional/plain-text fields', async () => {
    savedProfile.bio = '<script>not executable</script>';
    const { container } = openProfile('/profile?id=999');
    expect(await screen.findByText(initialProfile.email)).toBeInTheDocument();
    expect(screen.getByText(savedProfile.bio)).toBeInTheDocument();
    expect(container.querySelector('script')).toBeNull();
    expect(screen.getByText('No favorite genres selected yet.')).toBeInTheDocument();
    expect(requests.every((request) => request.url === '/v1/me/profile')).toBe(true);
    expect(screen.queryByLabelText(/role|permission/i)).not.toBeInTheDocument();
  });

  it('redirects guests before exposing profile data', async () => {
    respond = async (config) => response(config, 401, { message: 'Unauthenticated.' });
    openProfile();
    expect(await screen.findByRole('heading', { name: 'Test login' })).toBeInTheDocument();
    expect(screen.queryByText(initialProfile.email)).not.toBeInTheDocument();
    expect(patches).toEqual([]);
  });

  it('shows loading after authentication while the profile request is pending', async () => {
    let calls = 0;
    const pending = [];
    respond = (config) => {
      if (++calls === 1) return profileResponse(config);
      return new Promise((resolve) => pending.push(() => resolve(profileResponse(config))));
    };
    openProfile();
    expect(await screen.findByText('Loading your profile…')).toBeInTheDocument();
    expect(screen.queryByText(initialProfile.email)).not.toBeInTheDocument();
    await act(async () => pending.forEach((resolve) => resolve()));
    expect(await screen.findByText(initialProfile.email)).toBeInTheDocument();
  });

  it('shows empty data without crashing after authentication', async () => {
    let calls = 0;
    respond = async (config) => ++calls === 1
      ? profileResponse(config) : response(config, 200, { data: null });
    openProfile();
    expect(await screen.findByText('No profile information was returned.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit profile' })).not.toBeInTheDocument();
  });

  it('handles failed retrieval and retries without a full reload', async () => {
    const user = userEvent.setup();
    let calls = 0;
    let failing = true;
    respond = async (config) => {
      if (++calls === 1 || !failing) return profileResponse(config);
      return response(config, 500, { message: 'Unavailable' });
    };
    openProfile();
    expect(await screen.findByRole('alert')).toHaveTextContent('couldn’t load');
    failing = false;
    await user.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText(initialProfile.email)).toBeInTheDocument();
  });
});

describe('profile editing and errors', () => {
  it('prefills the form and cancel discards changes without submitting', async () => {
    const user = userEvent.setup();
    openProfile();
    await openEditor(user);
    expect(screen.getByLabelText('Name')).toHaveValue(initialProfile.name);
    expect(screen.getByLabelText('Email')).toHaveValue(initialProfile.email);
    await user.clear(screen.getByLabelText('Name'));
    await user.type(screen.getByLabelText('Name'), 'Unsaved Name');
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByText(initialProfile.name)).toBeInTheDocument();
    expect(patches).toEqual([]);
  });

  it('saves only editable fields, displays server data, and updates shared account state', async () => {
    const user = userEvent.setup();
    openProfile();
    await openEditor(user);
    await user.clear(screen.getByLabelText('Name'));
    await user.type(screen.getByLabelText('Name'), 'Updated Sam');
    await user.type(screen.getByLabelText('Bio (optional)'), 'Movie fan');
    await user.click(screen.getByLabelText('Drama'));
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(await screen.findByText('Your profile was saved.')).toBeInTheDocument();
    expect(screen.getByText('Updated Sam')).toBeInTheDocument();
    expect(screen.getByText('Movie fan')).toBeInTheDocument();
    expect(patches).toEqual([{
      name: 'Updated Sam', email: initialProfile.email, bio: 'Movie fan', favorite_genres: ['drama'],
    }]);
    const csrfIndex = requests.findIndex((request) => request.url.endsWith('/sanctum/csrf-cookie'));
    const patchIndex = requests.findIndex((request) => request.method === 'patch');
    expect(csrfIndex).toBeGreaterThanOrEqual(0);
    expect(csrfIndex).toBeLessThan(patchIndex);
    expect(requests[patchIndex].withCredentials).toBe(true);
    await user.click(screen.getByRole('link', { name: 'Back to home' }));
    expect(screen.getByText('Updated Sam')).toBeInTheDocument();
  });

  it('prevents client-invalid updates before contacting the save endpoint', async () => {
    const user = userEvent.setup();
    openProfile();
    await openEditor(user);
    await user.clear(screen.getByLabelText('Name'));
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(screen.getByLabelText('Name')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('Enter a name between 1 and 255 characters.')).toBeInTheDocument();
    expect(patches).toEqual([]);
  });

  it('shows Laravel email and indexed genre errors, keeps drafts, and never claims success', async () => {
    const user = userEvent.setup();
    const normal = respond;
    respond = async (config) => config.method === 'patch'
      ? response(config, 422, { errors: {
        email: ['The email has already been taken.'],
        'favorite_genres.0': ['This genre is not supported.'],
      } }) : normal(config);
    openProfile();
    await openEditor(user);
    await user.clear(screen.getByLabelText('Email'));
    await user.type(screen.getByLabelText('Email'), 'taken@example.com');
    await user.type(screen.getByLabelText('Current password'), ' spaced password ');
    await user.type(screen.getByLabelText('Bio (optional)'), 'Keep this draft');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(await screen.findByText('The email has already been taken.')).toBeInTheDocument();
    expect(screen.getByText('This genre is not supported.')).toBeInTheDocument();
    expect(screen.getByLabelText('Bio (optional)')).toHaveValue('Keep this draft');
    expect(screen.getByLabelText('Current password')).toHaveValue('');
    expect(screen.queryByText('Your profile was saved.')).not.toBeInTheDocument();
    expect(patches[0].current_password).toBe(' spaced password ');
  });

  it.each([419, 500, 'network'])('handles save failure %s without false success', async (failure) => {
    const user = userEvent.setup();
    const normal = respond;
    respond = async (config) => {
      if (config.method !== 'patch') return normal(config);
      if (failure === 'network') throw new AxiosError('Network Error', 'ERR_NETWORK', config);
      return response(config, failure, { message: 'Test failure' });
    };
    openProfile();
    await openEditor(user);
    await user.type(screen.getByLabelText('Bio (optional)'), 'Retain me');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      failure === 419 ? 'security session expired' : 'couldn’t confirm',
    );
    expect(screen.getByLabelText('Bio (optional)')).toHaveValue('Retain me');
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled();
    expect(screen.queryByText('Your profile was saved.')).not.toBeInTheDocument();
  });

  it('redirects when a session expires during save', async () => {
    const user = userEvent.setup();
    const normal = respond;
    respond = async (config) => config.method === 'patch'
      ? response(config, 401, { message: 'Unauthenticated.' }) : normal(config);
    openProfile();
    await openEditor(user);
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(await screen.findByRole('heading', { name: 'Test login' })).toBeInTheDocument();
    expect(screen.queryByText('Your profile was saved.')).not.toBeInTheDocument();
  });

  it('disables controls while saving and prevents repeated submits', async () => {
    const user = userEvent.setup();
    const normal = respond;
    let resolveSave;
    respond = (config) => config.method === 'patch'
      ? new Promise((resolve) => { resolveSave = () => resolve(profileResponse(config)); })
      : normal(config);
    openProfile();
    await openEditor(user);
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await screen.findByText('Saving your profile…');
    expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Saving…' }));
    expect(patches).toHaveLength(1);
    await act(async () => resolveSave());
    expect(await screen.findByText('Your profile was saved.')).toBeInTheDocument();
  });
});

describe('completion guidance', () => {

  it('reacts to explicit backend feature denial without blocking profile editing', async () => {
    const user = userEvent.setup();
    const normal = respond;
    respond = async (config) => config.url === '/v1/movies/search'
      ? response(config, 403, { code: 'PROFILE_INCOMPLETE', incomplete_fields: ['email'] })
      : normal(config);
    openProfile('/');
    await screen.findByText(initialProfile.name);
    await user.click(screen.getByRole('button', { name: 'Request restricted feature' }));
    expect(await screen.findByRole('heading', { name: 'Complete your profile' })).toBeInTheDocument();
    expect(screen.getByRole('status', { name: 'Profile completion' })).toHaveTextContent('Email');
    await user.click(screen.getByRole('link', { name: 'Complete profile' }));
    expect(await screen.findByRole('form', { name: 'Edit profile' })).toBeInTheDocument();
  });

  it('does not interpret an unrelated 403 as an incomplete profile', async () => {
    const user = userEvent.setup();
    const normal = respond;
    respond = async (config) => config.url === '/v1/movies/search'
      ? response(config, 403, { code: 'FORBIDDEN' }) : normal(config);
    openProfile('/');
    await screen.findByText(initialProfile.name);
    await user.click(screen.getByRole('button', { name: 'Request restricted feature' }));
    await waitFor(() => expect(requests.some((request) => request.url === '/v1/movies/search')).toBe(true));
    expect(screen.queryByRole('heading', { name: 'Complete your profile' })).not.toBeInTheDocument();
  });
  it('opens editing from a home notice and removes notice and edit query after repair', async () => {
    const user = userEvent.setup();
    complete = false;
    savedProfile.name = '';
    openProfile('/');
    await user.click(await screen.findByRole('link', { name: 'Complete profile' }));
    expect(await screen.findByRole('form', { name: 'Edit profile' })).toBeInTheDocument();
    expect(screen.getByLabelText('Current location')).toHaveTextContent('/profile?edit=1');
    await user.type(screen.getByLabelText('Name'), 'Repaired Sam');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await screen.findByText('Your profile was saved.');
    expect(screen.queryByRole('heading', { name: 'Complete your profile' })).not.toBeInTheDocument();
    expect(screen.getByLabelText('Current location')).toHaveTextContent(/^\/profile$/);
  });

  it('keeps optional fields optional and does not invent a completion warning', async () => {
    openProfile();
    await screen.findByText(initialProfile.email);
    expect(screen.getByText('No bio added yet.')).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Complete your profile' })).not.toBeInTheDocument();
  });
});
