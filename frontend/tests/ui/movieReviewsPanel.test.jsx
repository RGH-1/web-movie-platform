import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { AxiosError } from 'axios';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import MovieReviewsPanel from '../../src/components/MovieReviewsPanel.jsx';
import { AuthContext } from '../../src/context/AuthContext.jsx';
import PublicProfilePage from '../../src/pages/PublicProfilePage.jsx';
import api from '../../src/services/api.js';

const movie = { tmdb_id: 597, local_movie_id: '42' };
const ownReview = { id: '17', rating: 4, review_text: 'Original text.' };
const author = { id: '8', name: 'Review author' };
const reviewUrl = '/v1/catalog/movies/42/reviews';
let originalAdapter;

beforeEach(() => {
    originalAdapter = api.defaults.adapter;
});

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.restoreAllMocks();
});

function reply(config, data, status = 200) {
    return { config, data, status, headers: {}, statusText: String(status) };
}

function list(page, rows, { last = 1, count = rows.length, average = null } = {}) {
    return {
        data: rows,
        meta: {
            current_page: page,
            last_page: last,
            rating_count: count,
            rating_average: average,
        },
    };
}

function open(details = movie, user = { id: 8 }) {
    render(
        <MemoryRouter initialEntries={['/movies/597']}>
            <AuthContext.Provider value={{ user, loading: false, profileCompletion: null }}>
                <Routes>
                    <Route path="/movies/:tmdbId" element={<MovieReviewsPanel movie={details} />} />
                    <Route path="/users/:userId" element={<PublicProfilePage />} />
                </Routes>
            </AuthContext.Provider>
        </MemoryRouter>,
    );
}

it('lists rating-only reviews, paginates, and opens the correct author profile', async () => {
    api.defaults.adapter = async (config) => {
        if (config.url === '/v1/users/8/profile') {
            return reply(config, { data: author });
        }

        expect(config.url).toBe(reviewUrl);
        const page = config.params.page;
        return reply(config, list(page, [{
            id: String(page),
            author,
            rating: 5,
            review_text: page === 1 ? null : 'Page two review.',
            created_at: '2026-11-15T10:00:00.000000Z',
        }], { last: 2, count: 11, average: 4.25 }));
    };

    open(movie, null);
    expect(screen.getByRole('status')).toHaveTextContent(/Loading reviews/i);
    expect(await screen.findByText('Rating only')).toBeInTheDocument();
    expect(screen.getByText(/4\.25 \/ 5.*11 ratings/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: author.name })).toHaveAttribute('href', '/users/8');
    expect(screen.queryByRole('button', { name: 'Delete review' })).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(await screen.findByText('Page two review.')).toBeInTheDocument();
    expect(screen.getByText(/4\.25 \/ 5.*11 ratings/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('link', { name: author.name }));
    expect(await screen.findByRole('heading', { name: author.name })).toBeInTheDocument();
});

it('retries a failed list request and shows an empty state', async () => {
    let attempts = 0;
    api.defaults.adapter = async (config) => {
        if (++attempts === 1) throw new AxiosError('Unavailable');
        return reply(config, list(1, []));
    };

    open();
    expect(await screen.findByText('Could not load reviews.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Retry reviews' }));
    expect(await screen.findByText('No reviews yet.')).toBeInTheDocument();
    expect(attempts).toBe(2);
});

it('edits the owner review, confirms deletion, and refetches the statistics', async () => {
    const confirm = vi.spyOn(window, 'confirm')
        .mockReturnValueOnce(false)
        .mockReturnValueOnce(true);
    let stored = ownReview;
    const writes = [];
    const loadedPages = [];

    api.defaults.adapter = async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return reply(config, null, 204);

        if (config.method === 'patch') {
            writes.push([config.method, config.url]);
            stored = { ...stored, ...JSON.parse(config.data) };
            return reply(config, { data: stored });
        }

        if (config.method === 'delete') {
            writes.push([config.method, config.url]);
            stored = null;
            return reply(config, null, 204);
        }

        expect(config.url).toBe(reviewUrl);
        loadedPages.push(config.params.page);
        return reply(config, stored
            ? list(1, [{ ...stored, author }], { average: stored.rating })
            : list(1, []));
    };

    open({ ...movie, viewer_review: ownReview });
    expect(await screen.findByText('Original text.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Edit review' }));
    expect(screen.getByRole('radio', { name: '4 out of 5' })).toBeChecked();
    fireEvent.click(screen.getByRole('radio', { name: '5 out of 5' }));
    fireEvent.change(screen.getByLabelText('Review (optional)'), {
        target: { value: 'Edited text.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    const reviewList = () => screen.getByRole('region', { name: 'Movie reviews' });

    await waitFor(() => {
        expect(within(reviewList()).getByText('Edited text.')).toBeInTheDocument();
        expect(reviewList()).toHaveTextContent(/Platform rating:\s*5 \/ 5\s*\(1 rating\)/);
    });

    fireEvent.click(screen.getByRole('button', { name: 'Delete review' }));
    expect(writes).toEqual([['patch', '/v1/reviews/17']]);

    fireEvent.click(screen.getByRole('button', { name: 'Delete review' }));
    expect(await screen.findByText('Review deleted.')).toBeInTheDocument();
    expect(await screen.findByText('No reviews yet.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Submit review' })).toBeEnabled();
    expect(confirm).toHaveBeenCalledTimes(2);
    expect(writes).toEqual([['patch', '/v1/reviews/17'], ['delete', '/v1/reviews/17']]);
    expect(loadedPages).toEqual([1, 1, 1]);
});