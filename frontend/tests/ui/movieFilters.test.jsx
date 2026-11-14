import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MovieFilters from '../../src/components/MovieFilters';
import MovieDiscovery from '../../src/pages/MovieDiscovery';
import { discoverMovies, getMovieGenres } from '../../src/services/movies';

vi.mock('../../src/services/movies', () => ({ discoverMovies: vi.fn(), getMovieGenres: vi.fn(), searchMovies: vi.fn() }));
const defaults = { genre_id: '', year: '', min_rating: '', sort_by: 'popularity_desc' };
const results = { data: [{ tmdb_id: 597, title: 'Titanic', release_date: '1997-12-19' }], meta: { page: 1, total_pages: 3, total_results: 45 } };
const show = () => render(<MemoryRouter><MovieFilters /></MemoryRouter>);
async function ready() { await screen.findByText('Titanic'); }

beforeEach(() => {
    vi.resetAllMocks();
    getMovieGenres.mockResolvedValue([{ id: 987, name: 'Provider genre' }]);
    discoverMovies.mockResolvedValue(results);
});

describe('filtered movie discovery', () => {
    it('keeps title search available alongside filtered browsing', async () => {
        render(<MemoryRouter><MovieDiscovery /></MemoryRouter>);
        expect(screen.getByRole('searchbox', { name: 'Movie title' })).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Browse by filters' }));
        await ready();
        expect(screen.queryByRole('searchbox', { name: 'Movie title' })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Search by title' }));
        expect(screen.getByRole('searchbox', { name: 'Movie title' })).toBeVisible();
    });

    it('loads default movies and builds the genre selector from Laravel', async () => {
        show();
        await ready();
        expect(discoverMovies).toHaveBeenCalledWith(defaults, 1);
        expect(await screen.findByRole('option', { name: 'Provider genre' })).toHaveValue('987');
        expect(screen.getByRole('link', { name: 'View details for Titanic' })).toHaveAttribute('href', '/movies/597');
    });

    it('combines filters, preserves applied filters during pagination and resets new submissions to page one', async () => {
        show();
        await ready();
        fireEvent.change(screen.getByLabelText('Genre'), { target: { value: '987' } });
        fireEvent.change(screen.getByLabelText('Release year'), { target: { value: '2024' } });
        fireEvent.change(screen.getByLabelText('Minimum rating'), { target: { value: '7.5' } });
        fireEvent.change(screen.getByLabelText('Sort by'), { target: { value: 'rating_desc' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
        const applied = { genre_id: '987', year: '2024', min_rating: '7.5', sort_by: 'rating_desc' };
        await waitFor(() => expect(discoverMovies).toHaveBeenLastCalledWith(applied, 1));
        await ready();
        fireEvent.change(screen.getByLabelText('Release year'), { target: { value: '2020' } });
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await waitFor(() => expect(discoverMovies).toHaveBeenLastCalledWith(applied, 2));
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
        await waitFor(() => expect(discoverMovies).toHaveBeenLastCalledWith({ ...applied, year: '2020' }, 1));
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Reset' }));
        await waitFor(() => expect(discoverMovies).toHaveBeenLastCalledWith(defaults, 1));
    });

    it('offers all six sorts and shows empty results successfully', async () => {
        discoverMovies.mockResolvedValue({ data: [], meta: { page: 1, total_pages: 0, total_results: 0 } });
        show();
        expect(await screen.findByText(/No movies found on this page/)).toBeInTheDocument();
        expect([...screen.getByLabelText('Sort by').options].map(option => option.value)).toEqual([
            'popularity_desc', 'popularity_asc', 'rating_desc', 'rating_asc', 'release_date_desc', 'release_date_asc',
        ]);
        expect(screen.queryByRole('button', { name: 'Next' })).not.toBeInTheDocument();
    });

    it('keeps other filters available when genres fail and retries the list', async () => {
        getMovieGenres.mockRejectedValueOnce(new Error('unavailable'));
        show();
        await ready();
        expect(await screen.findByText(/Genres are temporarily unavailable/)).toBeInTheDocument();
        expect(screen.getByLabelText('Genre')).toBeDisabled();
        expect(screen.getByLabelText('Release year')).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Retry genres' }));
        expect(await screen.findByRole('option', { name: 'Provider genre' })).toBeInTheDocument();
    });

    it('shows backend validation errors and supports retry after a provider failure', async () => {
        discoverMovies.mockRejectedValueOnce({ response: { status: 422, data: { errors: { year: ['Unsupported release year.'] } } } });
        show();
        expect(await screen.findByText('Unsupported release year.')).toBeInTheDocument();
        discoverMovies.mockRejectedValueOnce({ response: { status: 503 } });
        fireEvent.click(screen.getByRole('button', { name: 'Retry discovery' }));
        expect(await screen.findByText(/Movie discovery is busy/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry discovery' }));
        await ready();
        expect(screen.queryByText(/Movie discovery is busy/)).not.toBeInTheDocument();
    });
});
