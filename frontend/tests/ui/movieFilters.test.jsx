import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MovieFilters from '../../src/components/MovieFilters';
import MovieDiscovery from '../../src/pages/MovieDiscovery';
import { discoverMovies, getMovieGenres, getPopularMovies, getUpcomingMovies, searchMovies } from '../../src/services/movies';

vi.mock('../../src/services/movies', () => ({ discoverMovies: vi.fn(), getMovieGenres: vi.fn(), searchMovies: vi.fn(), getPopularMovies: vi.fn(), getUpcomingMovies: vi.fn() }));
const defaults = { genre_id: '', year: '', min_rating: '', sort_by: 'popularity_desc' };
const results = { data: [{ tmdb_id: 597, title: 'Titanic', release_date: '1997-12-19' }], meta: { page: 1, total_pages: 3, total_results: 45 } };
const show = () => render(<MemoryRouter><MovieFilters /></MemoryRouter>);
async function ready() { await screen.findByText('Titanic'); }

beforeEach(() => {
    vi.resetAllMocks();
    sessionStorage.clear();
    getMovieGenres.mockResolvedValue([{ id: 987, name: 'Provider genre' }]);
    discoverMovies.mockImplementation((filters, page) => Promise.resolve({ ...results, meta: { ...results.meta, page } }));
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
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
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

it.each([
    ['Genre', '987', 'genre_id'],
    ['Release year', '2024', 'year'],
    ['Minimum rating', '8', 'min_rating'],
])('applies %s independently and shows the applied value rather than later draft edits', async (label, value, key) => {
    show();
    await ready();
    await screen.findByRole('option', { name: 'Provider genre' });
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    await ready();
    expect(discoverMovies).toHaveBeenLastCalledWith({ ...defaults, [key]: value }, 1);
    const applied = screen.getByLabelText('Applied filters');
    expect(applied).toHaveTextContent(key === 'genre_id' ? 'Provider genre' : value);
    fireEvent.change(screen.getByLabelText(label), { target: { value: '' } });
    expect(applied).toHaveTextContent(key === 'genre_id' ? 'Provider genre' : value);
});

it.each(['popularity_desc', 'popularity_asc', 'rating_desc', 'rating_asc', 'release_date_desc', 'release_date_asc'])(
    'submits sort %s and resets to page 1', async (sort) => {
        show();
        await ready();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await screen.findByText('Page 2 of 3');
        fireEvent.change(screen.getByLabelText('Sort by'), { target: { value: sort } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
        await screen.findByText('Page 1 of 3');
        expect(discoverMovies).toHaveBeenLastCalledWith({ ...defaults, sort_by: sort }, 1);
    }
);

it.each(['1800', '2024.5', '9999'])('rejects invalid year %s before calling Laravel', async (year) => {
    show();
    await ready();
    fireEvent.change(screen.getByLabelText('Release year'), { target: { value: year } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Enter a four-digit year');
    expect(discoverMovies).toHaveBeenCalledTimes(1);
});

it.each(['-1', '10.1'])('rejects out-of-range TMDB rating %s', async (rating) => {
    show();
    await ready();
    fireEvent.change(screen.getByLabelText('Minimum rating'), { target: { value: rating } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Enter a TMDB rating from 0 to 10');
    expect(discoverMovies).toHaveBeenCalledTimes(1);
});

it('labels the rating scale, jumps directly to a page with applied filters, and clears to defaults', async () => {
    discoverMovies.mockImplementation((filters, page) => Promise.resolve({ ...results, meta: { ...results.meta, page, total_pages: 500 } }));
    show();
    await ready();
    expect(screen.getByLabelText('Minimum rating')).toHaveAccessibleDescription('TMDB rating out of 10. Platform user ratings use 1–5.');
    fireEvent.change(screen.getByLabelText('Minimum rating'), { target: { value: '7.5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
    await ready();
    const input = screen.getByRole('spinbutton', { name: 'Page number' });
    fireEvent.change(input, { target: { value: '45' } });
    fireEvent.submit(input.form);
    await screen.findByText('Page 45 of 500');
    expect(discoverMovies).toHaveBeenLastCalledWith({ ...defaults, min_rating: '7.5' }, 45);
    fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    await screen.findByText('Page 1 of 500');
    expect(discoverMovies).toHaveBeenLastCalledWith(defaults, 1);
    expect(screen.getByLabelText('Minimum rating')).toHaveValue(null);
    expect(screen.getByLabelText('Applied filters')).toHaveTextContent('Any rating');
});

it('keeps popular/upcoming/search accessible and ignores filtered results arriving after a mode switch', async () => {
    let finish;
    discoverMovies.mockReturnValueOnce(new Promise(resolve => { finish = resolve; }))
        .mockResolvedValue({ ...results, data: [{ tmdb_id: 10, title: 'New discovery' }] });
    getPopularMovies.mockResolvedValue({ ...results, data: [{ tmdb_id: 20, title: 'Popular film' }] });
    getUpcomingMovies.mockResolvedValue({ ...results, data: [{ tmdb_id: 30, title: 'Upcoming film' }] });
    searchMovies.mockResolvedValue({ ...results, data: [{ tmdb_id: 40, title: 'Title result' }] });
    render(<MemoryRouter><MovieDiscovery /></MemoryRouter>);
    fireEvent.click(screen.getByRole('button', { name: 'Browse by filters' }));
    await screen.findByText('Loading movies...');
    const categories = screen.getByRole('navigation', { name: 'Movie categories' });
    fireEvent.click(within(categories).getByRole('button', { name: 'Popular' }));
    await screen.findByRole('heading', { name: 'Popular film' });
    await act(async () => finish(results));
    expect(screen.queryByRole('heading', { name: 'Titanic' })).not.toBeInTheDocument();
    fireEvent.click(within(categories).getByRole('button', { name: 'Upcoming' }));
    await screen.findByRole('heading', { name: 'Upcoming film' });
    fireEvent.click(within(categories).getByRole('button', { name: 'Title search' }));
    await screen.findByRole('heading', { name: 'New discovery' });
    fireEvent.click(screen.getByRole('button', { name: 'Search by title' }));
    fireEvent.change(screen.getByLabelText('Movie title'), { target: { value: 'Title' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search', exact: true }));
    await screen.findByRole('heading', { name: 'Title result' });
    expect(searchMovies).toHaveBeenCalledWith('Title', 1);
});
