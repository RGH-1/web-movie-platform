import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route, Link } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { AuthContext } from '../../src/context/AuthContext.jsx';
import PublicProfilePage from '../../src/pages/PublicProfilePage.jsx';
import api from '../../src/services/api.js';

const originalAdapter = api.defaults.adapter;
afterEach(() => { api.defaults.adapter = originalAdapter; });
const profile = { id: '8', name: 'Public author', followers_count: 2, following_count: 3,
  is_following: false, reviews: [{ id: '1', movie_id: '42', rating: 5, review_text: 'Great film' }],
  public_lists: [{ id: '4', name: 'Favorites', movies_count: 2 }], email: 'private@example.com' };
function open(user = null) {
  render(<MemoryRouter initialEntries={['/users/8']}><AuthContext.Provider value={{ user,
    isAuthenticated: !!user, loading: false }}><Routes><Route path="/users/:userId" element={<PublicProfilePage />} />
    </Routes></AuthContext.Provider></MemoryRouter>);
}
function respond(data = profile) {
  api.defaults.adapter = vi.fn(async (config) => ({ config, data: { data }, status: 200, headers: {} }));
}
it('allows guests to read public content and prompts login without exposing private fields', async () => {
  respond(); open();
  expect(screen.getByRole('status')).toHaveTextContent('Loading profile');
  await screen.findByRole('heading', { name: 'Public author' });
  expect(screen.getByRole('link', { name: 'Log in to follow' })).toHaveAttribute('href', '/login');
  expect(screen.getByRole('link', { name: 'View movie' })).toHaveAttribute('href', '/movies/local/42');
  expect(screen.getByRole('link', { name: 'Favorites' })).toHaveAttribute('href', '/lists/4');
  expect(screen.queryByText('private@example.com')).not.toBeInTheDocument();
});
it('persists follow and unfollow and uses the returned counts', async () => {
  api.defaults.adapter = vi.fn(async (config) => {
    const data = config.url.endsWith('/follow')
      ? { ...profile, is_following: config.method === 'post', followers_count: config.method === 'post' ? 3 : 2 }
      : profile;
    return { config, data: { data }, status: 200, headers: {} };
  });
  open({ id: 7 });
  fireEvent.click(await screen.findByRole('button', { name: 'Follow' }));
  expect(await screen.findByRole('button', { name: 'Unfollow' })).toBeEnabled();
  expect(screen.getByText('3 followers · 3 following')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Unfollow' }));
  await waitFor(() => expect(screen.getByText('2 followers · 3 following')).toBeInTheDocument());
  expect(api.defaults.adapter).toHaveBeenCalledWith(expect.objectContaining({ method: 'delete', url: '/v1/users/8/follow' }));
});
it('hides self-follow controls', async () => {
  respond(); open({ id: 8 });
  await screen.findByRole('heading', { name: 'Public author' });
  expect(screen.queryByRole('button', { name: /follow/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Log in to follow' })).not.toBeInTheDocument();
});
it('keeps counts unchanged when following fails and permits retry', async () => {
  respond(); open({ id: 7 });
  await screen.findByRole('button', { name: 'Follow' });
  api.defaults.adapter = async () => { throw new Error('offline'); };
  fireEvent.click(screen.getByRole('button', { name: 'Follow' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not update follow status');
  expect(screen.getByText('2 followers · 3 following')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Follow' })).toBeEnabled();
});
it('shows not-found state', async () => {
  api.defaults.adapter = async () => { throw { response: { status: 404 } }; };
  open();
  expect(await screen.findByRole('alert')).toHaveTextContent('Profile not found');
});
it('retries a failed profile request', async () => {
  api.defaults.adapter = async () => { throw new Error('offline'); };
  open();
  await screen.findByRole('alert');
  respond();
  fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
  expect(await screen.findByRole('heading', { name: 'Public author' })).toBeInTheDocument();
});

it('prevents duplicate requests while a follow change is pending', async () => {
  respond(); open({ id: 7 });
  await screen.findByRole('button', { name: 'Follow' });
  let finish;
  const adapter = vi.fn((config) => config.url.endsWith('/follow') ? new Promise((resolve) => {
    finish = () => resolve({ config, data: { data: { ...profile, is_following: true, followers_count: 3 } }, status: 200, headers: {} });
  }) : Promise.resolve({ config, data: {}, status: 204, headers: {} }));
  api.defaults.adapter = adapter;
  fireEvent.click(screen.getByRole('button', { name: 'Follow' }));
  const pending = await screen.findByRole('button', { name: /Updating/ });
  expect(pending).toBeDisabled();
  fireEvent.click(pending);
  await waitFor(() => expect(adapter.mock.calls.filter(([config]) => config.url.endsWith('/follow'))).toHaveLength(1));
  finish();
  expect(await screen.findByRole('button', { name: 'Unfollow' })).toBeEnabled();
});

it('uses the current viewer state when navigating to another profile', async () => {
  api.defaults.adapter = vi.fn(async (config) => ({ config, status: 200, headers: {}, data: { data:
    config.url.includes('/9/') ? { ...profile, id: '9', name: 'Second author', is_following: true } : profile,
  } }));
  render(<MemoryRouter initialEntries={['/users/8']}><AuthContext.Provider value={{ user: { id: 7 }, isAuthenticated: true, loading: false }}>
    <Routes><Route path="/users/:userId" element={<><PublicProfilePage /><Link to="/users/9">Next profile</Link></>} /></Routes>
  </AuthContext.Provider></MemoryRouter>);
  await screen.findByRole('button', { name: 'Follow' });
  fireEvent.click(screen.getByRole('link', { name: 'Next profile' }));
  expect(await screen.findByRole('heading', { name: 'Second author' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Unfollow' })).toBeEnabled();
  expect(screen.queryByRole('heading', { name: 'Public author' })).not.toBeInTheDocument();
});
