import { fireEvent, render, screen, within } from '@testing-library/react';
import { AxiosError } from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import App from '../../src/App';
import { useAuth } from '../../src/hooks/useAuth';
import api from '../../src/services/api';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));

const originalAdapter = api.defaults.adapter;
const id = '9007199254740993';
const endpoint = '/v1/admin/movies';
const movie = {
    local_movie_id: id, title: 'Moonlight', source: 'tmdb', tmdb_id: 376867,
    is_active: true, poster_url: null, release_date: '2016-10-21', runtime_minutes: 111,
    overview: 'An overview.', director: 'A director', genres: [{ tmdb_id: 18, name: 'Drama' }],
    backdrop_url: null, cast: [], trailer: null, metadata_overrides: [],
};

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.clearAllMocks();
});

function open(admin = true) {
    useAuth.mockReturnValue({ user: { can_access_admin: admin }, isAuthenticated: true, loading: false });
    render(<MemoryRouter initialEntries={['/admin/movies']}><App /></MemoryRouter>);
}

function response(config, data, status = 200) {
    return { config, data, status, headers: {} };
}

function getPage(config, data, lastPage = 1) {
    return response(config, { data, meta: { current_page: config.params.page, last_page: lastPage } });
}

function enter(label, value) {
    fireEvent.change(screen.getByLabelText(label, { exact: true }), { target: { value } });
}

it('blocks non-admins before requesting private catalog data', () => {
    api.defaults.adapter = vi.fn();
    open(false);
    expect(screen.getByRole('alert')).toHaveTextContent('Administrator access is required.');
    expect(api.defaults.adapter).not.toHaveBeenCalled();
});

it('lists and filters stored movies, paginates, and links by canonical ID', async () => {
    api.defaults.adapter = vi.fn(async (config) => getPage(config, [movie], 2));
    open();

    expect(screen.getByRole('status')).toHaveTextContent('Loading movies');
    expect(await screen.findByText('TMDB · 376867')).toBeInTheDocument();
    expect(screen.getByText('No poster')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View details for Moonlight' })).toHaveAttribute('href', `/movies/local/${id}`);
    enter('Search by title', ' Moon ');
    enter('Source', 'tmdb');
    enter('Status', 'active');
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    await screen.findByText('Page 1 of 2');
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 2');
    expect(api.defaults.adapter).toHaveBeenLastCalledWith(expect.objectContaining({
        url: endpoint, params: { search: 'Moon', source: 'tmdb', status: 'active', page: 2 },
    }));
});

it('validates and creates a local movie with structured credits and a trailer', async () => {
    let created;
    api.defaults.adapter = vi.fn(async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.method === 'post') {
            const payload = JSON.parse(config.data);
            expect(config.url).toBe(endpoint);
            expect(payload).toEqual({ title: 'Local short', runtime_minutes: 25,
                genres: [{ name: 'Drama', tmdb_id: null }],
                cast: [{ name: 'An actor', character: 'The lead', tmdb_person_id: null, profile_url: null }],
                trailer: { site: 'YouTube', key: 'abcdefghijk' } });
            created = { ...payload, local_movie_id: '44', source: 'local', is_active: true, poster_url: null };
            return response(config, { data: created }, 201);
        }
        return getPage(config, created ? [created] : []);
    });
    open();
    fireEvent.click(await screen.findByRole('button', { name: 'Create local movie' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save movie' }));
    expect(screen.getByLabelText('Title', { exact: true })).toHaveAccessibleDescription('Enter title.');

    enter('Title', ' Local short ');
    enter('Runtime (minutes)', '25');
    enter('YouTube video ID', 'abcdefghijk');
    fireEvent.click(screen.getByRole('button', { name: 'Add genre' }));
    enter('Genre name', 'Drama');
    fireEvent.click(screen.getByRole('button', { name: 'Add cast member' }));
    enter('Actor name', 'An actor');
    enter('Character', 'The lead');
    fireEvent.click(screen.getByRole('button', { name: 'Save movie' }));
    expect(await screen.findByText('Movie created.')).toBeInTheDocument();
    expect(await screen.findByRole('heading', { name: 'Local short' })).toBeInTheDocument();
    expect(within(screen.getByRole('article')).getByText('Local')).toBeInTheDocument();
});

it('loads the TMDB record, keeps form values on API validation, and patches only changed metadata', async () => {
    let saved = movie;
    let attempts = 0;
    api.defaults.adapter = vi.fn(async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.method === 'patch') {
            const payload = JSON.parse(config.data);
            expect(config.url).toBe(`${endpoint}/${id}`);
            expect(payload).toEqual({ director: null, genres: [] });
            if (++attempts === 1) throw new AxiosError('Invalid director', 'ERR_BAD_RESPONSE', config, null,
                response(config, { errors: { director: ['Server validation message.'] } }, 422));
            saved = { ...movie, ...payload };
            return response(config, { data: saved });
        }
        if (config.url === `${endpoint}/${id}`) return response(config, { data: saved });
        return getPage(config, [saved]);
    });
    open();
    fireEvent.click(await screen.findByRole('button', { name: 'Edit Moonlight' }));
    expect(await screen.findByText(/TMDB ID 376867/)).toBeInTheDocument();
    expect(screen.getByLabelText('Title')).toHaveValue('Moonlight');
    expect(screen.getByLabelText('Release date')).toHaveValue('2016-10-21');
    enter('Director', '');
    fireEvent.click(screen.getByRole('button', { name: 'Remove genre 1' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save movie' }));
    expect(await screen.findByText('Server validation message.')).toBeInTheDocument();
    expect(screen.getByLabelText('Director')).toHaveValue('');
    fireEvent.click(screen.getByRole('button', { name: 'Save movie' }));
    expect(await screen.findByText('Movie updated.')).toBeInTheDocument();
    expect(await screen.findByText('TMDB · 376867')).toBeInTheDocument();
});

it('deactivates only after confirmation and removes the public details link', async () => {
    let saved = movie;
    api.defaults.adapter = vi.fn(async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.method === 'delete') {
            expect(config.url).toBe(`${endpoint}/${id}`);
            saved = { ...movie, is_active: false };
            return response(config, { data: saved });
        }
        return getPage(config, [saved]);
    });
    open();
    fireEvent.click(await screen.findByRole('button', { name: 'Deactivate Moonlight' }));
    const confirmation = screen.getByRole('region', { name: 'Confirm deactivation' });
    fireEvent.click(within(confirmation).getByRole('button', { name: 'Cancel' }));
    expect(api.defaults.adapter.mock.calls.some(([request]) => request.method === 'delete')).toBe(false);

    fireEvent.click(screen.getByRole('button', { name: 'Deactivate Moonlight' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm deactivation' }));
    expect(await screen.findByText('Movie deactivated.')).toBeInTheDocument();
    const card = await screen.findByRole('article');
    expect(within(card).getByText('Deactivated')).toBeInTheDocument();
    expect(within(card).queryByRole('link')).not.toBeInTheDocument();
});

it('distinguishes a retryable load error from an empty catalog', async () => {
    api.defaults.adapter = vi.fn().mockRejectedValueOnce(new Error('Offline'))
        .mockImplementationOnce(async (config) => getPage(config, []));
    open();
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load catalog movies.');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('No movies match your filters.')).toBeInTheDocument();
});
