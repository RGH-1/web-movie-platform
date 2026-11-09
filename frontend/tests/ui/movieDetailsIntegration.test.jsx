import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MovieDetailNavigation from '../../src/pages/MovieDetailNavigation';
import { getMovieDetails } from '../../src/services/movies';
import api from '../../src/services/api';
import { movieDetailsResponse } from '../fixtures/movieDetails';

let originalAdapter;
let respond;
let requests;

function reply(config, status, data, headers = {}) {
    const response = { config, status, data, headers, statusText: String(status) };
    if (status >= 400) {
        throw new AxiosError('Test response', 'ERR_BAD_RESPONSE', config, null, response);
    }
    return response;
}

function openDetails() {
    return render(
        <MemoryRouter initialEntries={['/movies/157336']}>
            <Link to="/movies/603">Another movie</Link>
            <Routes>
                <Route path="/movies/:tmdbId" element={<MovieDetailNavigation />} />
                <Route path="/login" element={<h1>Sign in</h1>} />
            </Routes>
        </MemoryRouter>,
    );
}

beforeEach(() => {
    originalAdapter = api.defaults.adapter;
    requests = [];
    respond = (config) => reply(config, 200, movieDetailsResponse);
    api.defaults.adapter = async (config) => {
        requests.push(config);
        return respond(config);
    };
});

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.useRealTimers();
});

describe('movie details integration (AB#55)', () => {
    it('uses the existing Laravel service path and renders returned cast images/trailer', async () => {
        respond = (config) => reply(config, 200, { data: {
            ...movieDetailsResponse.data,
            cast: [{ ...movieDetailsResponse.data.cast[0], profile_url: 'https://example.com/cast.jpg' }],
        } });
        openDetails();
        expect(await screen.findByRole('heading', { name: 'Interstellar' })).toBeInTheDocument();
        expect(requests[0].url).toBe('/v1/movies/157336');
        expect(screen.getByRole('img', { name: 'Matthew McConaughey' })).toHaveAttribute('src', 'https://example.com/cast.jpg');
        expect(screen.getByRole('link', { name: 'Watch Trailer' })).toHaveAttribute(
            'href', 'https://www.youtube.com/watch?v=zSWdZVtXT7E',
        );
    });

    it('handles missing optional fields in an actual service response', async () => {
        respond = (config) => reply(config, 200, { data: { tmdb_id: 157336, title: 'Minimal movie' } });
        openDetails();
        expect(await screen.findByRole('heading', { name: 'Minimal movie' })).toBeInTheDocument();
        expect(screen.getByText('No cast information available.')).toBeInTheDocument();
        expect(screen.getByText('No genres available.')).toBeInTheDocument();
        expect(screen.getByText('No trailer available.')).toBeInTheDocument();
    });

    it.each([
        null,
        { tmdb_id: 603, title: 'Wrong movie' },
        { tmdb_id: 157336 },
    ])('rejects unusable or mismatched successful responses: %j', async (data) => {
        respond = (config) => reply(config, 200, { data });
        openDetails();
        expect(await screen.findByRole('alert')).toHaveTextContent('Unable to load movie details.');
        expect(screen.getByRole('button', { name: 'Try again' })).toBeEnabled();
        expect(screen.queryByRole('heading', { name: 'Wrong movie' })).not.toBeInTheDocument();
    });

    it('retries a failed request only when requested by the user', async () => {
        respond = (config) => requests.length === 1
            ? reply(config, 502, { message: 'Unavailable' }) : reply(config, 200, movieDetailsResponse);
        openDetails();
        await screen.findByRole('alert');
        expect(requests).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByRole('heading', { name: 'Interstellar' })).toBeInTheDocument();
        expect(requests).toHaveLength(2);
    });

    it('respects Retry-After and does not automatically retry a throttled response', async () => {
        vi.useFakeTimers();
        respond = (config) => reply(config, 503, { message: 'Busy' }, { 'retry-after': '2' });
        openDetails();
        await act(async () => {});
        expect(screen.getByRole('button', { name: 'Try again' })).toBeDisabled();
        await act(async () => vi.advanceTimersByTimeAsync(1999));
        expect(screen.getByRole('button', { name: 'Try again' })).toBeDisabled();
        await act(async () => vi.advanceTimersByTimeAsync(1));
        expect(screen.getByRole('button', { name: 'Try again' })).toBeEnabled();
        expect(requests).toHaveLength(1);
    });

    it('shows the new movie while ignoring an earlier response that arrives late', async () => {
        let finishOld;
        respond = (config) => config.url.endsWith('/157336')
            ? new Promise((resolve) => { finishOld = () => resolve(reply(config, 200, movieDetailsResponse)); })
            : reply(config, 200, { data: { tmdb_id: 603, title: 'The Matrix' } });
        openDetails();
        await waitFor(() => expect(requests).toHaveLength(1));
        fireEvent.click(screen.getByRole('link', { name: 'Another movie' }));
        expect(await screen.findByRole('heading', { name: 'The Matrix' })).toBeInTheDocument();
        await act(async () => finishOld());
        expect(screen.getByRole('heading', { name: 'The Matrix' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Interstellar' })).not.toBeInTheDocument();
    });

    it('hides the old movie while loading a different route ID', async () => {
        let finishNew;
        respond = (config) => config.url.endsWith('/157336')
            ? reply(config, 200, movieDetailsResponse)
            : new Promise((resolve) => { finishNew = () => resolve(reply(config, 200, {
                data: { tmdb_id: 603, title: 'The Matrix' },
            })); });
        openDetails();
        await screen.findByRole('heading', { name: 'Interstellar' });
        fireEvent.click(screen.getByRole('link', { name: 'Another movie' }));
        expect(screen.getByRole('status')).toHaveTextContent('Loading movie details');
        expect(screen.queryByRole('heading', { name: 'Interstellar' })).not.toBeInTheDocument();
        await waitFor(() => expect(requests).toHaveLength(2));
        await act(async () => finishNew());
        expect(await screen.findByRole('heading', { name: 'The Matrix' })).toBeInTheDocument();
    });

    it('redirects expired sessions and provides a profile-repair action for 403', async () => {
        respond = (config) => reply(config, 403, { code: 'PROFILE_INCOMPLETE' });
        const view = openDetails();
        await screen.findByRole('alert');
        expect(screen.getByRole('link', { name: 'Complete profile' })).toHaveAttribute('href', '/profile?edit=1');
        view.unmount();

        respond = (config) => reply(config, 401, { message: 'Unauthenticated.' });
        openDetails();
        expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    });

    it('rejects invalid IDs before any HTTP request', async () => {
        await expect(getMovieDetails(0)).rejects.toThrow('Invalid movie ID.');
        await expect(getMovieDetails('abc')).rejects.toThrow('Invalid movie ID.');
        expect(requests).toEqual([]);
    });
});
