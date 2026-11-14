import { afterEach, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import MovieDiscovery from '../../src/pages/MovieDiscovery';
import { getPopularMovies, getUpcomingMovies, searchMovies } from '../../src/services/movies';

vi.mock('../../src/services/movies', () => ({
    getPopularMovies: vi.fn(), getUpcomingMovies: vi.fn(), searchMovies: vi.fn(),
}));

const feed = (title = 'Popular film', page = 1) => ({
    data: [{ tmdb_id: 597, local_movie_id: '42', title }],
    meta: { page, total_pages: 2, total_results: 21 },
});
const open = () => render(<MemoryRouter><MovieDiscovery /></MemoryRouter>);
const choose = (name) => fireEvent.click(within(screen.getByRole('navigation', {
    name: 'Movie categories',
})).getByRole('button', { name }));

afterEach(() => { vi.resetAllMocks(); sessionStorage.clear(); });

it('loads and paginates both categories using provider detail links', async () => {
    getPopularMovies.mockResolvedValueOnce(feed()).mockResolvedValueOnce(feed('Page two', 2));
    getUpcomingMovies.mockResolvedValue(feed('Upcoming film'));
    open();
    choose('Popular');
    expect(await screen.findByRole('link', { name: 'View details for Popular film' }))
        .toHaveAttribute('href', '/movies/597');
    expect(getPopularMovies).toHaveBeenCalledWith(1);
    expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByRole('heading', { name: 'Page two' });
    expect(getPopularMovies).toHaveBeenLastCalledWith(2);
    expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
    choose('Upcoming');
    await screen.findByRole('heading', { name: 'Upcoming film' });
    expect(getUpcomingMovies).toHaveBeenCalledWith(1);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 2');
    expect(getUpcomingMovies).toHaveBeenLastCalledWith(2);
});

it('ignores an old category response and shows loading and empty states', async () => {
    let finish;
    getPopularMovies.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
    getUpcomingMovies.mockResolvedValue({ data: [], meta: { page: 1, total_pages: 0 } });
    open();
    choose('Popular');
    expect(screen.getByRole('status')).toHaveTextContent('Loading popular movies');
    choose('Upcoming');
    await screen.findByText('No upcoming movies on this page.');
    await act(async () => finish(feed('Stale film')));
    expect(screen.queryByText('Stale film')).not.toBeInTheDocument();
});

it.each([503, 502, undefined])('handles feed failure %s and allows retry', async (status) => {
    getPopularMovies.mockRejectedValueOnce({ response: { status } }).mockResolvedValueOnce(feed());
    open();
    choose('Popular');
    expect(await screen.findByRole('alert')).toHaveTextContent(
        status === 503 ? 'Movie listings are busy' : 'Movie listings are temporarily unavailable');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    await screen.findByRole('heading', { name: 'Popular film' });
});

it('preserves search results and pagination while browsing categories', async () => {
    searchMovies.mockResolvedValueOnce(feed('Search film')).mockResolvedValueOnce(feed('Search page two', 2));
    getPopularMovies.mockResolvedValue(feed());
    open();
    fireEvent.change(screen.getByLabelText('Movie title'), { target: { value: 'Titanic' } });
    fireEvent.submit(screen.getByRole('search'));
    await screen.findByRole('heading', { name: 'Search film' });
    choose('Popular');
    await screen.findByRole('heading', { name: 'Popular film' });
    choose('Title search');
    expect(screen.getByLabelText('Movie title')).toHaveValue('Titanic');
    expect(screen.getByRole('heading', { name: 'Search film' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByRole('heading', { name: 'Search page two' });
    expect(searchMovies).toHaveBeenLastCalledWith('Titanic', 2);
});

it.each(['Popular', 'Upcoming', 'Title search'])('jumps to page 45 in %s without requesting intermediate pages', async (category) => {
    const load = category === 'Popular' ? getPopularMovies : category === 'Upcoming' ? getUpcomingMovies : searchMovies;
    load.mockImplementation((...args) => Promise.resolve({
        ...feed('Jump film', category === 'Title search' ? args[1] : args[0]),
        meta: { page: category === 'Title search' ? args[1] : args[0], total_pages: 500, total_results: 10000 },
    }));
    open();
    if (category === 'Title search') {
        fireEvent.change(screen.getByLabelText('Movie title'), { target: { value: 'Titanic' } });
        fireEvent.submit(screen.getByRole('search'));
    } else choose(category);
    const input = await screen.findByRole('spinbutton', { name: 'Page number' });
    fireEvent.change(input, { target: { value: '45' } });
    fireEvent.submit(input.form);
    await screen.findByText('Page 45 of 500');
    expect(load).toHaveBeenCalledTimes(2);
    expect(load).toHaveBeenLastCalledWith(...(category === 'Title search' ? ['Titanic', 45] : [45]));
});
