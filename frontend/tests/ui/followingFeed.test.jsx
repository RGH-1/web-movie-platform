import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';

import App from '../../src/App';
import { useAuth } from '../../src/hooks/useAuth';
import api from '../../src/services/api';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));
vi.mock('../../src/services/api', () => ({ default: { get: vi.fn() } }));

afterEach(() => vi.resetAllMocks());

function openFeed(authenticated = true) {
  useAuth.mockReturnValue({ isAuthenticated: authenticated, loading: false });
  return render(<MemoryRouter initialEntries={['/feed']}><App /></MemoryRouter>);
}

const author = { id: '7', name: 'Morgan' };
const timestamp = '2026-11-21T10:00:00+00:00';

it('renders the API feed in order and loads the next page', async () => {
  api.get.mockResolvedValueOnce({ data: {
    data: [
      { type: 'public_list', target_id: 'list-1', author, timestamp, metadata: { name: 'Weekend picks' } },
      { type: 'review', target_id: '4', author, timestamp, metadata: { movie_id: 'movie-1', title: 'Moonlight', rating: 5 } },
    ],
    meta: { current_page: 1, last_page: 2, per_page: 2, total: 3 },
  } }).mockResolvedValueOnce({ data: {
    data: [
      { type: 'rating', target_id: '5', author, timestamp, metadata: { movie_id: 'movie-2', title: 'Arrival', rating: 4 } },
    ],
    meta: { current_page: 2, last_page: 2, per_page: 2, total: 3 },
  } });

  openFeed();
  await screen.findByText('Weekend picks');
  const rows = screen.getAllByRole('listitem');
  expect(screen.getByRole('heading', { name: 'Public list' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Written review' })).toBeInTheDocument();
  expect(screen.getAllByRole('link', { name: 'Morgan' })[0]).toHaveAttribute('href', '/users/7');
  expect(rows[0]).toHaveTextContent('Morgan published Weekend picks');
  expect(rows[1]).toHaveTextContent('Morgan reviewed Moonlight (5/5)');
  expect(screen.getByRole('link', { name: 'Weekend picks' })).toHaveAttribute('href', '/lists/list-1');
  expect(screen.getByRole('link', { name: 'Moonlight' })).toHaveAttribute('href', '/movies/local/movie-1');

  await userEvent.click(screen.getByRole('button', { name: 'Next' }));
  await screen.findByText('Arrival');
  expect(screen.getByRole('heading', { name: 'Rating' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Arrival' })).toHaveAttribute('href', '/movies/local/movie-2');
  expect(screen.getByRole('listitem')).toHaveTextContent('Morgan rated Arrival (4/5)');
  expect(screen.queryByText('Weekend picks')).toBeNull();
  expect(api.get.mock.calls).toEqual([
    ['/v1/feed', { params: { page: 1 }, signal: expect.any(AbortSignal) }],
    ['/v1/feed', { params: { page: 2 }, signal: expect.any(AbortSignal) }],
  ]);
  expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
});

it('shows loading and a retryable error before an empty result', async () => {
  api.get.mockRejectedValueOnce(new Error('Offline')).mockResolvedValueOnce({ data: {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 },
  } });

  openFeed();
  expect(screen.getByRole('status')).toHaveTextContent('Loading activity');
  expect(await screen.findByRole('alert')).toHaveTextContent('Could not load the following feed.');
  await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
  expect(await screen.findByText('No activity on this page.')).toBeInTheDocument();
});

it('redirects anonymous users before requesting any feed activity', async () => {
  openFeed(false);
  await screen.findByRole('heading', { name: 'Welcome back' });
  expect(api.get).not.toHaveBeenCalled();
});
