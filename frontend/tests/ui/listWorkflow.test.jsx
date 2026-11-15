import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom';
import api from '../../src/services/api';
import { useAuth } from '../../src/hooks/useAuth';
import ProtectedRoute from '../../src/components/ProtectedRoute';
import MyListsPage from '../../src/pages/MyListsPage';
import ListDetailsPage from '../../src/pages/ListDetailsPage';
import AddToList from '../../src/components/AddToList';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));
const originalAdapter = api.defaults.adapter;
const movie = { local_movie_id: '9007199254740993', tmdb_id: 157336, title: 'Interstellar', is_active: true };
let saved;
let member;
let requests;
const metadata = () => ({ ...saved, movie_count: member ? 1 : 0 });
const meta = { current_page: 1, last_page: 1, per_page: 12, total: 1 };

beforeEach(() => {
    saved = null;
    member = false;
    requests = [];
    useAuth.mockReturnValue({ isAuthenticated: true, loading: false, user: { id: 1 }, profileCompletion: { complete: true, fields: [] } });
    // Keep the real frontend services; only replace the HTTP transport.
    api.defaults.adapter = async (config) => {
        requests.push({ method: config.method, url: config.url, body: config.data ? JSON.parse(config.data) : null });
        const reply = (data, status = 200) => ({ config, data, status, statusText: String(status), headers: {} });
        if (config.url.endsWith('/sanctum/csrf-cookie')) return reply(null, 204);
        if (config.url === '/v1/me/lists') return reply({ data: saved ? [metadata()] : [], meta });
        if (config.method === 'post' && config.url === '/v1/lists') {
            saved = { id: '7', ...JSON.parse(config.data), author: { id: '1', name: 'Lynn' } };
            return reply({ data: metadata() }, 201);
        }
        if (config.method === 'patch' && config.url === '/v1/lists/7') {
            saved = { ...saved, ...JSON.parse(config.data) };
            return reply({ data: metadata() });
        }
        if (config.method === 'post' && config.url === '/v1/movies/tmdb/157336/resolve') {
            return reply({ data: { tmdb_id: 157336, local_movie_id: movie.local_movie_id } });
        }
        if (config.method === 'post' && config.url === '/v1/lists/7/movies') {
            expect(JSON.parse(config.data)).toEqual({ movie_id: movie.local_movie_id });
            member = true;
            return reply({ data: metadata() });
        }
        if (config.method === 'delete' && config.url === `/v1/lists/7/movies/${movie.local_movie_id}`) {
            member = false;
            return reply(null, 204);
        }
        if (config.method === 'get' && config.url === '/v1/lists/7') {
            return reply({ data: { ...metadata(), movies: member ? [movie] : [] }, meta });
        }
        if (config.method === 'delete' && config.url === '/v1/lists/7') {
            saved = null;
            member = false;
            return reply(null, 204);
        }
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
});
afterEach(() => { api.defaults.adapter = originalAdapter; });

it('creates, edits, resolves/adds, views, removes, and deletes through real list services and connected pages', async () => {
    const user = userEvent.setup();
    render(<MemoryRouter initialEntries={['/my-lists']}>
        <Link to="/movies/157336">Open movie</Link>
        <Routes><Route element={<ProtectedRoute />}>
            <Route path="/my-lists" element={<MyListsPage />} />
            <Route path="/lists/:listId" element={<ListDetailsPage />} />
            <Route path="/movies/157336" element={<AddToList movie={{ ...movie, local_movie_id: null }} />} />
        </Route></Routes>
    </MemoryRouter>);
    await screen.findByText('You have no lists yet. Create your first list.');
    await user.click(screen.getByRole('button', { name: 'Create list' }));
    await user.type(screen.getByLabelText('List name'), 'Weekend');
    await user.click(screen.getByRole('button', { name: 'Save list' }));
    let card = await screen.findByRole('article', { name: 'Weekend' });
    expect(card).toHaveTextContent('Private');
    await user.click(within(card).getByRole('button', { name: 'Edit' }));
    await user.type(screen.getByLabelText('Description (optional)'), 'Shared favorites');
    await user.click(screen.getByRole('checkbox'));
    await user.click(screen.getByRole('button', { name: 'Save list' }));
    await screen.findByRole('link', { name: 'Weekend' });
    expect(saved.is_public).toBe(true);

    await user.click(screen.getByRole('link', { name: 'Open movie' }));
    await user.click(screen.getByRole('button', { name: 'Choose a list' }));
    await screen.findByRole('option', { name: 'Weekend (Public)' });
    await user.selectOptions(screen.getByLabelText('Choose one of your lists'), '7');
    await user.click(screen.getByRole('button', { name: 'Add movie' }));
    await screen.findByRole('link', { name: 'View list' });
    await user.click(screen.getByRole('button', { name: 'Add movie' }));
    await screen.findByRole('link', { name: 'View list' });
    await user.click(screen.getByRole('link', { name: 'View list' }));
    await screen.findByRole('heading', { name: 'Weekend' });
    expect(screen.getByText('Shared favorites')).toBeInTheDocument();
    expect(screen.getByText(/1 movie\(s\)/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View details for Interstellar' })).toHaveAttribute('href', '/movies/157336');
    await user.click(screen.getByRole('button', { name: 'Remove Interstellar from list' }));
    await screen.findByText('This list has no movies yet.');
    await user.click(screen.getByRole('link', { name: 'Back to My Lists' }));
    card = await screen.findByRole('article', { name: 'Weekend' });
    expect(card).toHaveTextContent('0 movie(s)');
    await user.click(within(card).getByRole('button', { name: 'Delete' }));
    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(saved).not.toBeNull();
    await user.click(within(card).getByRole('button', { name: 'Delete' }));
    await user.click(screen.getByRole('button', { name: 'Confirm delete' }));
    await screen.findByText('You have no lists yet. Create your first list.');
    expect(saved).toBeNull();
    expect(requests.filter(request => request.url.endsWith('/movies') && request.method === 'post')).toHaveLength(2);
    expect(requests.some(request => request.url.includes('/157336/resolve'))).toBe(true);
});
