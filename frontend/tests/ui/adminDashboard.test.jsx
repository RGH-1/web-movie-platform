import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import App from '../../src/App';
import { useAuth } from '../../src/hooks/useAuth';
import api from '../../src/services/api';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));

const originalAdapter = api.defaults.adapter;
const emptyDashboard = {
    kpis: { total_users: 1, total_reviews: 0 },
    most_watched_movies: [],
    highest_rated_movies: [],
    popular_genres: [],
    recent_activity: [],
};

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.clearAllMocks();
});

function open(canAccessAdmin = true) {
    useAuth.mockReturnValue({
        isAuthenticated: true,
        loading: false,
        user: { can_access_admin: canAccessAdmin },
    });
    render(<MemoryRouter initialEntries={['/admin/dashboard']}><App /></MemoryRouter>);
}

function response(config, data) {
    return { config, data: { data }, status: 200, headers: {} };
}

it('requests the dashboard and displays backend values and ordering', async () => {
    const occurredAt = '2026-11-24T12:15:00.000000Z';
    api.defaults.adapter = vi.fn(async (config) => response(config, {
        kpis: { total_users: 42, total_reviews: 17, visible_reviews: 8 },
        most_watched_movies: [
            { movie_id: '1', title: 'Arrival', watch_count: 12 },
            { movie_id: '2', title: 'Dune', watch_count: 7 },
        ],
        highest_rated_movies: [{ movie_id: '2', title: 'Dune', average_rating: 4.75, review_count: 3 }],
        popular_genres: [{ genre_id: 878, genre_name: 'Science Fiction', engagement_count: 15 }],
        recent_activity: [{
            event_type: 'movie_watched', user_id: '3', user_name: 'Morgan',
            movie_id: '2', movie_title: 'Dune', occurred_at: occurredAt,
        }],
    }));
    open();

    expect(screen.getByRole('status')).toHaveTextContent('Loading dashboard');
    const watched = await screen.findByRole('table', { name: 'Most watched movies' });
    expect(api.defaults.adapter).toHaveBeenCalledWith(expect.objectContaining({
        method: 'get', url: '/v1/admin/dashboard',
    }));
    expect(screen.getByText('Total users').nextElementSibling).toHaveTextContent('42');
    expect(screen.getByText('Total reviews').nextElementSibling).toHaveTextContent('17');
    expect(within(watched).getAllByRole('row').slice(1).map((row) => row.cells[0].textContent))
        .toEqual(['Arrival', 'Dune']);
    expect(within(watched).getByRole('row', { name: 'Arrival 12' })).toBeInTheDocument();
    expect(within(screen.getByRole('table', { name: 'Highest rated movies' }))
        .getByRole('row', { name: 'Dune 4.75 3' })).toBeInTheDocument();
    expect(within(screen.getByRole('table', { name: 'Popular genres' }))
        .getByRole('row', { name: 'Science Fiction 15' })).toBeInTheDocument();
    const activity = screen.getByRole('table', { name: 'Recent activity' });
    expect(within(activity).getByRole('row', { name: /Morgan Marked as watched Dune/ })
        .querySelector('time')).toHaveAttribute('datetime', occurredAt);
    expect(screen.getByRole('link', { name: 'User management' })).toHaveAttribute('href', '/admin/users');
});

it('retries a failed request and shows the empty state for every table', async () => {
    api.defaults.adapter = vi.fn()
        .mockRejectedValueOnce(new Error('Offline'))
        .mockImplementationOnce(async (config) => response(config, emptyDashboard));
    open();

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load dashboard metrics.');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('No recent activity.')).toBeInTheDocument();
    expect(screen.getByText('No movies have been marked as watched.')).toBeInTheDocument();
    expect(screen.getByText('No movies have visible reviews.')).toBeInTheDocument();
    expect(screen.getByText('No genre engagement has been recorded.')).toBeInTheDocument();
    expect(screen.getByText('Total reviews').nextElementSibling).toHaveTextContent('0');
});

it('blocks a non-admin before requesting any dashboard data', () => {
    api.defaults.adapter = vi.fn();
    open(false);

    expect(screen.getByRole('alert')).toHaveTextContent('Administrator access is required.');
    expect(api.defaults.adapter).not.toHaveBeenCalled();
});

it('honors a backend denial when the client still has admin access', async () => {
    api.defaults.adapter = vi.fn().mockRejectedValue({ response: { status: 403 } });
    open();

    expect(await screen.findByRole('alert')).toHaveTextContent('Administrator access is required.');
    expect(screen.queryByText('Total users')).not.toBeInTheDocument();
});
