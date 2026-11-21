import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { AuthContext } from '../../src/context/AuthContext.jsx';
import UserRelationshipListPage from '../../src/pages/UserRelationshipListPage.jsx';
import api from '../../src/services/api.js';

const originalAdapter = api.defaults.adapter;
const owner = { id: '8', name: 'Public author', followers_count: 2, following_count: 3,
  is_following: false, reviews: [], public_lists: [] };
const pageOne = [
  { id: '10', name: 'Ada', followers_count: 4, following_count: 5, is_following: false },
  { id: '11', name: 'Grace', followers_count: 6, following_count: 7, is_following: true },
];
const pageTwo = [{ id: '12', name: 'Lin', followers_count: 8, following_count: 9, is_following: false }];

afterEach(() => { api.defaults.adapter = originalAdapter; });

function response(config, data, status = 200, meta = null) {
  return { config, data: { data, ...(meta ? { meta } : {}) }, status, headers: {} };
}

function open({ user = null, relationship = 'followers' } = {}) {
  render(<MemoryRouter initialEntries={[`/users/8/${relationship}`]}>
    <AuthContext.Provider value={{ user, isAuthenticated: !!user, loading: false }}>
      <Routes>
        <Route path="/users/:userId/followers" element={<UserRelationshipListPage relationship="followers" />} />
        <Route path="/users/:userId/following" element={<UserRelationshipListPage relationship="following" />} />
        <Route path="/users/:userId" element={<p>Public profile</p>} />
      </Routes>
    </AuthContext.Provider>
  </MemoryRouter>);
}

function respondWithPages({ relationship = 'followers', users = pageOne } = {}) {
  api.defaults.adapter = vi.fn(async (config) => {
    if (config.url.endsWith('/profile')) return response(config, owner);
    if (config.url.endsWith(`/${relationship}`)) {
      const currentPage = Number(config.params?.page ?? 1);
      return response(config, currentPage === 2 ? pageTwo : users, 200, {
        current_page: currentPage, last_page: 2,
      });
    }
    return { config, data: {}, status: 204, headers: {} };
  });
}

it('shows follower cards with profile links and paginates', async () => {
  respondWithPages();
  open();
  expect(await screen.findByRole('heading', { name: 'Followers' })).toBeInTheDocument();
  expect(screen.getByText('Public author')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Ada' })).toHaveAttribute('href', '/users/10');
  expect(screen.getByRole('link', { name: 'Back to public profile' })).toHaveAttribute('href', '/users/8');
  fireEvent.click(screen.getByRole('button', { name: 'Next' }));
  expect(await screen.findByRole('link', { name: 'Lin' })).toHaveAttribute('href', '/users/12');
  expect(screen.queryByRole('link', { name: 'Ada' })).not.toBeInTheDocument();
});

it('uses the following endpoint and permits guests to browse without follow controls', async () => {
  respondWithPages({ relationship: 'following' });
  open({ relationship: 'following' });
  expect(await screen.findByRole('heading', { name: 'Following' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Ada' })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /follow/i })).not.toBeInTheDocument();
  expect(api.defaults.adapter).toHaveBeenCalledWith(expect.objectContaining({ url: '/v1/users/8/following' }));
});

it('updates an eligible user card after following and hides controls on the viewer card', async () => {
  api.defaults.adapter = vi.fn(async (config) => {
    if (config.url.endsWith('/profile')) return response(config, owner);
    if (config.url.endsWith('/followers')) return response(config, [
      ...pageOne, { id: '7', name: 'Current user', followers_count: 1, following_count: 1, is_following: false },
    ], 200, { current_page: 1, last_page: 1 });
    if (config.url.endsWith('/follow')) return response(config, {
      id: '10', is_following: true, followers_count: 5, following_count: 5,
    });
    return { config, data: {}, status: 204, headers: {} };
  });
  open({ user: { id: 7 } });
  expect(await screen.findByRole('button', { name: 'Follow' })).toBeEnabled();
  expect(screen.getByLabelText('Current user').querySelector('button')).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Follow' }));
  expect(await screen.findByRole('button', { name: 'Unfollow' })).toBeEnabled();
  expect(screen.getByText('5 followers · 5 following')).toBeInTheDocument();
});

it('keeps card state unchanged when a follow request fails', async () => {
  api.defaults.adapter = async (config) => {
    if (config.url.endsWith('/profile')) return response(config, owner);
    if (config.url.endsWith('/followers')) return response(config, pageOne, 200, { current_page: 1, last_page: 1 });
    if (config.url.endsWith('/follow')) throw new Error('offline');
    return { config, data: {}, status: 204, headers: {} };
  };
  open({ user: { id: 7 } });
  fireEvent.click(await screen.findByRole('button', { name: 'Follow' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not update follow status');
  expect(screen.getByText('4 followers · 5 following')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Follow' })).toBeEnabled();
});

it('shows empty, missing-profile, and retryable failure states', async () => {
  respondWithPages({ users: [] });
  open();
  expect(await screen.findByText('No followers yet.')).toHaveTextContent('No followers yet');

  api.defaults.adapter = async () => { throw { response: { status: 404 } }; };
  open({ relationship: 'following' });
  expect(await screen.findByRole('alert')).toHaveTextContent('Profile not found');
});

it('retries failed list requests without retaining stale cards', async () => {
  api.defaults.adapter = async () => { throw new Error('offline'); };
  open();
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not load followers');
  respondWithPages();
  fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
  expect(await screen.findByRole('link', { name: 'Ada' })).toBeInTheDocument();
  await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
});
