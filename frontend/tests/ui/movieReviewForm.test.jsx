import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AxiosError } from 'axios';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import MovieReviewSection from '../../src/components/MovieReviewSection.jsx';
import { AuthContext } from '../../src/context/AuthContext.jsx';
import MovieDetailNavigation from '../../src/pages/MovieDetailNavigation.jsx';
import api from '../../src/services/api.js';

const MOVIE_ID = '9007199254740993';
const movie = { tmdb_id: 597, title: 'Titanic', local_movie_id: MOVIE_ID };
const session = { user: { id: 8 }, loading: false, profileCompletion: null };
let originalAdapter;
let requests;
let respond;

function reply(config, status, data) {
    const response = { config, status, data, headers: {}, statusText: String(status) };
    if (status >= 400) {
        throw new AxiosError('Test response', 'ERR_BAD_RESPONSE', config, null, response);
    }
    return response;
}

beforeEach(() => {
    originalAdapter = api.defaults.adapter;
    requests = [];
    respond = (config) => {
        const payload = JSON.parse(config.data);
        return reply(config, 200, { data: { id: '17', ...payload } });
    };

    api.defaults.adapter = async (config) => {
        requests.push(config);
        if (config.url.endsWith('/sanctum/csrf-cookie')) return reply(config, 204);
        return respond(config);
    };
});

afterEach(() => {
    api.defaults.adapter = originalAdapter;
});

function openSection({ details = movie, auth = session, onReload = vi.fn() } = {}) {
    render(
        <MemoryRouter initialEntries={['/movies/597']}>
            <AuthContext.Provider value={auth}>
                <MovieReviewSection movie={details} onReload={onReload} />
            </AuthContext.Provider>
        </MemoryRouter>,
    );
}

function selectRating(value) {
    fireEvent.click(screen.getByRole('radio', { name: `${value} out of 5` }));
}

function changeText(value) {
    fireEvent.change(screen.getByLabelText('Review (optional)'), { target: { value } });
}

it('uses the canonical ID from Movie Details and creates a rating without text', async () => {
    respond = (config) => config.method === 'get'
        ? reply(config, 200, { data: movie })
        : reply(config, 201, { data: { id: '17', rating: 4, review_text: null } });

    render(
        <MemoryRouter initialEntries={['/movies/597']}>
            <AuthContext.Provider value={session}>
                <Routes>
                    <Route path="/movies/:tmdbId" element={<MovieDetailNavigation />} />
                </Routes>
            </AuthContext.Provider>
        </MemoryRouter>,
    );

    await screen.findByRole('form', { name: 'Movie review' });
    selectRating('4');
    fireEvent.click(screen.getByRole('button', { name: 'Submit review' }));

    expect(await screen.findByText('Review submitted.')).toBeInTheDocument();
    expect(requests.map(({ method, url }) => [method, url])).toEqual([
        ['get', '/v1/movies/597'],
        ['get', 'http://127.0.0.1:8000/sanctum/csrf-cookie'],
        ['post', `/v1/catalog/movies/${MOVIE_ID}/reviews`],
    ]);
    expect(JSON.parse(requests[2].data)).toEqual({ rating: 4, review_text: null });
    expect(screen.getByRole('button', { name: 'Save changes' })).toBeEnabled();
});

it('prefills an existing review and patches its trimmed text', async () => {
    openSection({
        details: { ...movie, viewer_review: { id: '17', rating: 5, review_text: 'Original' } },
    });

    expect(screen.getByRole('radio', { name: '5 out of 5' })).toBeChecked();
    expect(screen.getAllByText('★')).toHaveLength(5);
    expect(screen.getByLabelText('Review (optional)')).toHaveValue('Original');

    changeText('  Updated review.  ');
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    expect(await screen.findByText('Review updated.')).toBeInTheDocument();
    expect(requests[1]).toMatchObject({ method: 'patch', url: '/v1/reviews/17' });
    expect(JSON.parse(requests[1].data)).toEqual({ rating: 5, review_text: 'Updated review.' });
});

it('blocks a missing rating and overlong text before sending a request', () => {
    openSection();
    changeText('x'.repeat(2001));

    fireEvent.click(screen.getByRole('button', { name: 'Submit review' }));

    expect(screen.getByText('Choose a rating from 1 to 5.')).toBeInTheDocument();
    expect(screen.getByText('Use 2,000 characters or fewer.')).toBeInTheDocument();
    expect(requests).toHaveLength(0);
});

it('blocks repeated submits while the first save is pending', async () => {
    let finish;
    respond = (config) => new Promise((resolve) => {
        finish = () => resolve(reply(config, 201, {
            data: { id: '17', rating: 4, review_text: 'My review.' },
        }));
    });

    openSection();
    selectRating('4');
    changeText('My review.');
    const form = screen.getByRole('form', { name: 'Movie review' });

    act(() => {
        fireEvent.submit(form);
        fireEvent.submit(form);
    });

    await waitFor(() => expect(finish).toBeTypeOf('function'));
    expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    expect(requests.filter(({ method }) => method === 'post')).toHaveLength(1);

    await act(async () => finish());
    expect(screen.getByText('Review submitted.')).toBeInTheDocument();
});

it('shows field errors and keeps the draft for a retry', async () => {
    respond = (config) => reply(config, 422, {
        errors: { review_text: ['Please revise your review.'] },
    });
    openSection();
    selectRating('3');
    changeText('My draft');

    fireEvent.click(screen.getByRole('button', { name: 'Submit review' }));

    expect(await screen.findByText('Please revise your review.')).toBeInTheDocument();
    expect(screen.getByLabelText('Review (optional)')).toHaveValue('My draft');
    expect(screen.getByRole('button', { name: 'Submit review' })).toBeEnabled();
});

it('offers a details reload when the server reports an existing review', async () => {
    respond = (config) => reply(config, 409, { code: 'REVIEW_ALREADY_EXISTS' });
    const onReload = vi.fn();
    openSection({ onReload });
    selectRating('4');
    fireEvent.click(screen.getByRole('button', { name: 'Submit review' }));

    fireEvent.click(await screen.findByRole('button', { name: 'Reload movie details' }));

    expect(onReload).toHaveBeenCalledOnce();
    expect(screen.getByRole('button', { name: 'Submit review' })).toBeDisabled();
});

it('prompts a guest to sign in', () => {
    openSection({ auth: { ...session, user: null } });

    expect(screen.getByRole('link', { name: 'Sign in to leave a review' }))
        .toHaveAttribute('href', '/login');
    expect(requests).toHaveLength(0);
});

it('shows the unavailable state without a catalog mapping', () => {
    openSection({ details: { ...movie, local_movie_id: null } });

    expect(screen.getByText('Reviews are unavailable for this movie.')).toBeInTheDocument();
    expect(screen.queryByRole('form', { name: 'Movie review' })).toBeNull();
});

it('fills the stars up to the selected rating', () => {
    openSection();

    selectRating('4');
    expect(screen.getByRole('radio', { name: '4 out of 5' })).toBeChecked();
    expect(screen.getAllByText('★')).toHaveLength(4);
    expect(screen.getAllByText('☆')).toHaveLength(1);

    selectRating('2');
    expect(screen.getByRole('radio', { name: '2 out of 5' })).toBeChecked();
    expect(screen.getAllByText('★')).toHaveLength(2);
    expect(screen.getAllByText('☆')).toHaveLength(3);
});