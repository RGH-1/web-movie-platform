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
const author = { id: '7', name: 'Morgan' };
const review = {
    id, author, rating: 4, review_text: 'A thoughtful review.',
    movie: { local_movie_id: '13', title: 'Moonlight', is_active: true },
    moderation: { status: 'visible', reason: null, moderated_at: null, moderator: null },
};

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.clearAllMocks();
});

function open({ admin = true, signedIn = true } = {}) {
    useAuth.mockReturnValue({ user: { can_access_admin: admin }, isAuthenticated: signedIn, loading: false });
    return render(<MemoryRouter initialEntries={['/admin/reviews']}><App /></MemoryRouter>);
}

function response(config, data, status = 200) {
    return { config, data, status, headers: {} };
}

function getPage(config, reviews, lastPage = 1) {
    const { page } = config.params;
    return response(config, { data: reviews, meta: { current_page: page, last_page: lastPage } });
}

it('requires administrator access and redirects guests before loading any reviews', async () => {
    api.defaults.adapter = vi.fn();
    const view = open({ admin: false });
    expect(screen.getByRole('alert')).toHaveTextContent('Administrator access is required.');
    expect(api.defaults.adapter).not.toHaveBeenCalled();
    view.unmount();

    open({ signedIn: false });
    expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeInTheDocument();
    expect(api.defaults.adapter).not.toHaveBeenCalled();
});


it('filters reviews, returns to the last available page after hiding, and restores with a reason', async () => {
    let current = review;
    const first = { ...review, id: '2', movie: { ...review.movie, title: 'First movie' } };
    const requests = vi.fn(async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.method === 'patch') {
            expect(config.url).toBe(`/v1/admin/reviews/${id}/moderation`);
            const fields = JSON.parse(config.data);
            current = {
                ...current, moderation: {
                    ...fields,
                    moderated_at: '2026-11-23T09:00:00Z',
                    moderator: { id: '1', name: 'Site admin' },
                },
            };
            return response(config, { data: current });
        }
        const { status, page } = config.params;
        if (status === 'hidden') return getPage(config, current.moderation.status === 'hidden' ? [current] : []);
        if (status === 'visible') {
            const lastPage = current.moderation.status === 'visible' ? 2 : 1;
            return getPage(config, page === 1 ? [first] : lastPage === 2 ? [current] : [], lastPage);
        }
        return getPage(config, [current]);
    });
    api.defaults.adapter = requests;
    open();
    expect(screen.getByRole('status')).toHaveTextContent('Loading reviews');
    expect(await screen.findByRole('link', { name: 'Moonlight' })).toHaveAttribute('href', '/movies/local/13');
    expect(screen.getByRole('link', { name: 'Morgan' })).toHaveAttribute('href', '/users/7');
    expect(screen.getByText('A thoughtful review.')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Review status'), { target: { value: 'visible' } });
    await screen.findByRole('link', { name: 'First movie' });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 2');

    fireEvent.click(screen.getByRole('button', { name: 'Hide review of Moonlight by Morgan' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm hide' }));
    expect(screen.getByLabelText('Moderation reason')).toHaveAccessibleDescription('Enter a reason of 1 to 1000 characters.');
    expect(requests.mock.calls.some(([request]) => request.method === 'patch')).toBe(false);
    fireEvent.change(screen.getByLabelText('Moderation reason'), { target: { value: '  Contains spoilers.  ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm hide' }));
    expect(await screen.findByText('Review hidden.')).toBeInTheDocument();
    await screen.findByRole('link', { name: 'First movie' });
    expect(screen.queryByRole('link', { name: 'Moonlight' })).not.toBeInTheDocument();
    expect(requests).toHaveBeenLastCalledWith(expect.objectContaining({ params: { page: 1, status: 'visible' } }));

    fireEvent.change(screen.getByLabelText('Review status'), { target: { value: 'hidden' } });
    expect(await screen.findByText('Reason: Contains spoilers.')).toBeInTheDocument();
    expect(screen.getByText(/Last moderated by Site admin/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Restore review of Moonlight by Morgan' }));
    fireEvent.change(screen.getByLabelText('Moderation reason'), { target: { value: 'Reviewed and cleared.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm restore' }));
    expect(await screen.findByText('Review restored.')).toBeInTheDocument();
    expect(await screen.findByText('No reviews match this status.')).toBeInTheDocument();

    const writes = requests.mock.calls.flatMap(([{ method, url, data }]) => {
        if (method === 'patch') return [JSON.parse(data)];
        return url.endsWith('/sanctum/csrf-cookie') ? ['csrf'] : [];
    });
    expect(writes).toEqual(['csrf', { status: 'hidden', reason: 'Contains spoilers.' }, 'csrf',
        { status: 'visible', reason: 'Reviewed and cleared.' }]);
});

it('preserves the entered reason and review status when Laravel rejects a change', async () => {
    api.defaults.adapter = vi.fn(async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.method === 'patch') throw new AxiosError('Invalid reason', 'ERR_BAD_RESPONSE', config, null,
            response(config, { errors: { reason: ['Laravel rejected this reason.'] } }, 422));
        return getPage(config, [review]);
    });
    open();
    fireEvent.click(await screen.findByRole('button', { name: 'Hide review of Moonlight by Morgan' }));
    fireEvent.change(screen.getByLabelText('Moderation reason'), { target: { value: 'Draft reason' } });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm hide' }));
    expect(await screen.findByText('Laravel rejected this reason.')).toBeInTheDocument();
    expect(screen.getByLabelText('Moderation reason')).toHaveValue('Draft reason');
    expect(screen.queryByText('Review hidden.')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.getByRole('button', { name: 'Hide review of Moonlight by Morgan' })).toBeInTheDocument();
});

it('distinguishes a failed list request from a successful empty result', async () => {
    api.defaults.adapter = vi.fn().mockRejectedValueOnce(new Error('Offline'))
        .mockImplementationOnce(async (config) => getPage(config, []));
    open();
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load reviews.');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('No reviews match this status.')).toBeInTheDocument();
});
