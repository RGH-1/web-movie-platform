import { fireEvent, render, screen } from '@testing-library/react';
import { AxiosError } from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import App from '../../src/App';
import { useAuth } from '../../src/hooks/useAuth';
import api from '../../src/services/api';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));

const originalAdapter = api.defaults.adapter;
const id = '9007199254740993';
const apiPath = `/v1/movie-nights/${id}`;
const detailsPath = `/movie-nights/${id}`;
const draft = {
    id,
    title: 'Weekend movies',
    starts_at: '2099-12-29T18:00:43.000000Z',
    timezone: 'Asia/Beirut',
    organizer: { id: '4', name: 'Morgan' },
    description: null,
    location: null,
    meeting_url: null,
    status: 'draft',
    viewer_role: 'organizer',
    permissions: { update: true, cancel: true },
    summaries: { participants: { count: 0 }, invitations: null, shortlist: null },
};

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.clearAllMocks();
});

function open(path = '/movie-nights', signedIn = true) {
    useAuth.mockReturnValue({ isAuthenticated: signedIn, loading: false });
    render(<MemoryRouter initialEntries={[path]}><App /></MemoryRouter>);
}

function response(config, data, status = 200) {
    return { config, data, status, headers: {} };
}

function reject(config, status, data = {}) {
    throw new AxiosError('Request failed', 'ERR_BAD_RESPONSE', config, null, response(config, data, status));
}

it('creates, schedules by editing, and cancels through the existing Laravel endpoints', async () => {
    let night = null;
    api.defaults.adapter = vi.fn(async (config) => {
        const { url, method } = config;
        if (url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (url === '/v1/movie-nights' && method === 'get') {
            return response(config, { data: [], meta: { current_page: 1, last_page: 1 } });
        }
        if (url === '/v1/movie-nights' && method === 'post') {
            const fields = JSON.parse(config.data);
            expect(fields).toEqual({
                title: draft.title, description: null, location: 'Room 2', meeting_url: 'https://example.com/meet',
                starts_at: new Date('2099-12-30T20:45').toISOString(),
                status: 'draft', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            });
            night = { ...draft, ...fields };
            return response(config, { data: night }, 201);
        }
        if (url === apiPath && method === 'get') return response(config, { data: night });
        if (url === apiPath && method === 'patch') {
            const fields = JSON.parse(config.data);
            expect(fields).toEqual({ status: 'scheduled', location: null });
            night = { ...night, ...fields };
            return response(config, { data: night });
        }
        if (url === `${apiPath}/cancel` && method === 'post') {
            return response(config, { data: { ...night, status: 'cancelled', permissions: { update: false, cancel: false } } });
        }
        throw new Error(`Unexpected request: ${method} ${url}`);
    });

    open();
    expect(await screen.findByText("You don't have any movie nights yet.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Plan a movie night' }));
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: draft.title } });
    fireEvent.change(screen.getByLabelText('Date and time'), { target: { value: '2099-12-30T20:45' } });
    fireEvent.change(screen.getByLabelText('Location (optional)'), { target: { value: 'Room 2' } });
    fireEvent.change(screen.getByLabelText('Meeting link (optional)'), { target: { value: 'https://example.com/meet' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save movie night' }));

    await screen.findByRole('heading', { name: draft.title });
    expect(screen.getByRole('link', { name: 'Open meeting link' })).toHaveAttribute('href', 'https://example.com/meet');
    fireEvent.click(screen.getByRole('button', { name: 'Edit movie night' }));
    expect(screen.getByLabelText('Date and time')).toHaveValue('2099-12-30T20:45');
    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'scheduled' } });
    fireEvent.change(screen.getByLabelText('Location (optional)'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save movie night' }));
    expect(await screen.findByText('Movie night saved.')).toBeInTheDocument();
    expect(screen.getByText('Scheduled', { selector: 'dd' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel movie night' }));
    expect(screen.getByRole('heading', { name: 'Cancel this movie night?' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Keep movie night' }));
    expect(screen.queryByRole('button', { name: 'Confirm cancellation' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel movie night' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm cancellation' }));
    expect(await screen.findByText('Movie night cancelled.')).toBeInTheDocument();
    expect(screen.getByText('Cancelled', { selector: 'dd' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit movie night' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel movie night' })).not.toBeInTheDocument();
    const writes = api.defaults.adapter.mock.calls.flatMap(([{ method, url }]) => method !== 'get' || url.endsWith('/sanctum/csrf-cookie') ? [method] : []);
    expect(writes).toEqual(['get', 'post', 'get', 'patch', 'get', 'post']);
});

it('keeps the form on screen and associates local and server validation with fields', async () => {
    api.defaults.adapter = vi.fn(async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.method === 'post') return reject(config, 422, { errors: { title: ['Server rejected this title.'] } });
        return response(config, { data: [], meta: { current_page: 1, last_page: 1 } });
    });
    open();
    fireEvent.click(await screen.findByRole('button', { name: 'Plan a movie night' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save movie night' }));
    expect(screen.getByLabelText('Title')).toHaveAccessibleDescription('Enter a title.');
    expect(screen.getByLabelText('Date and time')).toHaveAttribute('aria-invalid', 'true');
    expect(api.defaults.adapter).toHaveBeenCalledTimes(1);

    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Draft title' } });
    fireEvent.change(screen.getByLabelText('Date and time'), { target: { value: '2099-12-30T20:45' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save movie night' }));
    await screen.findByText('Server rejected this title.');
    expect(screen.getByLabelText('Title')).toHaveValue('Draft title');
    expect(screen.getByLabelText('Title')).toHaveAccessibleDescription('Server rejected this title.');
});

it('shows participant details without organizer controls, and gives unavailable tools honest labels', async () => {
    const participantNight = {
        ...draft, status: 'scheduled', viewer_role: 'participant',
        summaries: { ...draft.summaries, participants: { count: 2 } },
        permissions: { update: false, cancel: false },
    };
    api.defaults.adapter = async (config) => response(config, { data: participantNight });
    open(detailsPath);

    expect(await screen.findByRole('heading', { name: draft.title })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Morgan' })).toHaveAttribute('href', '/users/4');
    expect(screen.getByText('2 confirmed, excluding the organizer')).toBeInTheDocument();
    expect(screen.getByText('Asia/Beirut', { exact: false })).toHaveAttribute('dateTime', draft.starts_at);
    expect(screen.getByText('Calendar export')).toHaveTextContent('Calendar export Unavailable');
    expect(screen.queryByRole('button', { name: 'Edit movie night' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel movie night' })).not.toBeInTheDocument();
});

it('handles loading, retry, and pagination for the movie-night list', async () => {
    api.defaults.adapter = vi.fn().mockRejectedValueOnce(new Error('Offline')).mockImplementation(async (config) => {
        const page = config.params.page;
        return response(config, {
            data: [{ ...draft, title: page === 1 ? draft.title : 'Second event' }],
            meta: { current_page: page, last_page: 2 },
        });
    });
    open();

    expect(screen.getByRole('status')).toHaveTextContent('Loading movie nights');
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load your movie nights');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('link', { name: draft.title })).toHaveAttribute('href', detailsPath);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(await screen.findByRole('link', { name: 'Second event' })).toBeInTheDocument();
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();
});

it('uses the same safe message for a missing or inaccessible movie-night ID', async () => {
    api.defaults.adapter = async (config) => reject(config, 404);
    open(detailsPath);
    expect(await screen.findByRole('alert')).toHaveTextContent('Movie night not found or unavailable to your account.');
    expect(screen.queryByRole('button', { name: 'Edit movie night' })).not.toBeInTheDocument();
});
