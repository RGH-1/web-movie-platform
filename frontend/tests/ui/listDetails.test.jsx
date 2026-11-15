import { beforeEach, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { Link, MemoryRouter, Route, Routes } from 'react-router-dom';
import ListDetailsPage from '../../src/pages/ListDetailsPage';
import AddToList from '../../src/components/AddToList';
import MovieDetailNavigation from '../../src/pages/MovieDetailNavigation';
import { useAuth } from '../../src/hooks/useAuth';
import { getList, getMyLists, addMovieToList, removeMovieFromList } from '../../src/services/lists';
import { getLocalMovieDetails } from '../../src/services/movies';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));
vi.mock('../../src/services/lists', () => ({ getList: vi.fn(), getMyLists: vi.fn(), addMovieToList: vi.fn(), removeMovieFromList: vi.fn() }));
vi.mock('../../src/services/movies', () => ({ getLocalMovieDetails: vi.fn(), getMovieDetails: vi.fn() }));
vi.mock('../../src/components/MovieStatusActions.jsx', () => ({ default: () => null }));
vi.mock('../../src/components/MovieReviewSection.jsx', () => ({ default: () => null }));
const id = '9007199254740993';
const movie = { local_movie_id: '42', tmdb_id: 157336, title: 'Interstellar', is_active: true };
const details = (movies = [movie], page = 1, last = 1) => ({ data: { id, name: 'Weekend', description: 'My favorites', is_public: true, author: { id: '1', name: 'Lynn' }, movie_count: movies.length, movies }, meta: { current_page: page, last_page: last } });
const lists = (page = 1, last = 1) => ({ data: [{ id, name: 'Weekend', is_public: false }], meta: { current_page: page, last_page: last } });
function open(url = `/lists/${id}`) {
    return render(<MemoryRouter initialEntries={[url]}><Link to="/lists/2">Another list</Link><Routes>
        <Route path="/lists/:listId" element={<ListDetailsPage />} />
        <Route path="/movies/local/:movieId" element={<MovieDetailNavigation />} />
        <Route path="/login" element={<h1>Login destination</h1>} />
    </Routes></MemoryRouter>);
}
function selector(value = movie) { return render(<MemoryRouter><AddToList movie={value} /></MemoryRouter>); }
beforeEach(() => {
    vi.resetAllMocks();
    useAuth.mockReturnValue({ user: { id: 1 }, profileCompletion: { complete: true, fields: [] } });
    getList.mockResolvedValue(details());
    getMyLists.mockResolvedValue(lists());
    addMovieToList.mockResolvedValue({});
});

it('renders shareable list metadata and provider links, with owner removal', async () => {
    open();
    await screen.findByRole('heading', { name: 'Weekend' });
    expect(screen.getByText('By Lynn')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View details for Interstellar' })).toHaveAttribute('href', '/movies/157336');
    expect(screen.getByRole('button', { name: 'Remove Interstellar from list' })).toBeEnabled();
    expect(getList).toHaveBeenCalledWith(id, 1, expect.any(Object));
});

it('gives another signed-in user a read-only public view', async () => {
    useAuth.mockReturnValue({ user: { id: 2 } });
    open();
    await screen.findByRole('heading', { name: 'Weekend' });
    expect(screen.queryByRole('button', { name: /Remove/ })).not.toBeInTheDocument();
    expect(screen.queryByText('Manage your lists')).not.toBeInTheDocument();
});

it('removes an owner membership using its canonical string and recovers an empty last page', async () => {
    getList.mockResolvedValueOnce(details([movie], 1, 2)).mockResolvedValueOnce(details([movie], 2, 2))
        .mockResolvedValueOnce(details([], 2, 1)).mockResolvedValueOnce(details([]));
    removeMovieFromList.mockResolvedValue(undefined);
    open();
    await screen.findByRole('heading', { name: 'Weekend' });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 2');
    fireEvent.click(screen.getByRole('button', { name: 'Remove Interstellar from list' }));
    await screen.findByText('This list has no movies yet.');
    expect(removeMovieFromList).toHaveBeenCalledWith(id, '42', expect.any(Object));
    expect(getList).toHaveBeenLastCalledWith(id, 1, expect.any(Object));
});

it('uses local-only movie links and lets owners remove inactive entries without opening them', async () => {
    getList.mockResolvedValue(details([{ ...movie, tmdb_id: null, local_movie_id: id }, { ...movie, local_movie_id: '43', title: 'Inactive', is_active: false }]));
    open();
    expect(await screen.findByRole('link', { name: 'View details for Interstellar' })).toHaveAttribute('href', `/movies/local/${id}`);
    expect(screen.queryByRole('link', { name: 'View details for Inactive' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Remove Inactive from list' })).toBeEnabled();
});

it.each([404, 401])('handles list access error %s without showing metadata', async (status) => {
    getList.mockRejectedValue({ response: { status } });
    open();
    if (status === 404) await screen.findByText('List not found or unavailable to your account.');
    else await screen.findByRole('heading', { name: 'Login destination' });
    expect(screen.queryByText('My favorites')).not.toBeInTheDocument();
});

it('retries a list outage and preserves a card when removal fails', async () => {
    getList.mockRejectedValueOnce(new Error('offline')).mockResolvedValue(details());
    removeMovieFromList.mockRejectedValue(new Error('offline'));
    open();
    await screen.findByText('This list could not be loaded. Please try again.');
    fireEvent.click(screen.getByRole('button', { name: 'Refresh list' }));
    await screen.findByRole('heading', { name: 'Interstellar' });
    fireEvent.click(screen.getByRole('button', { name: 'Remove Interstellar from list' }));
    await screen.findByText('The movie could not be removed. Please try again.');
    expect(screen.getByRole('heading', { name: 'Interstellar' })).toBeInTheDocument();
});

it('ignores late responses when navigating to another list', async () => {
    let finish;
    getList.mockReturnValueOnce(new Promise(resolve => { finish = resolve; })).mockResolvedValueOnce({ ...details([]), data: { ...details([]).data, name: 'Second' } });
    open();
    fireEvent.click(screen.getByRole('link', { name: 'Another list' }));
    await screen.findByRole('heading', { name: 'Second' });
    await act(async () => finish(details()));
    expect(screen.queryByRole('heading', { name: 'Weekend' })).not.toBeInTheDocument();
});

it('loads selector pages and supports repeated adds without duplicate error messages', async () => {
    getMyLists.mockResolvedValueOnce(lists(1, 2)).mockResolvedValueOnce(lists(2, 2));
    selector();
    expect(getMyLists).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Choose a list' }));
    await screen.findByRole('option', { name: 'Weekend (Private)' });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 2');
    fireEvent.change(screen.getByLabelText('Choose one of your lists'), { target: { value: id } });
    fireEvent.click(screen.getByRole('button', { name: 'Add movie' }));
    expect(await screen.findByRole('link', { name: 'View list' })).toHaveAttribute('href', `/lists/${id}`);
    fireEvent.click(screen.getByRole('button', { name: 'Add movie' }));
    await screen.findByRole('link', { name: 'View list' });
    expect(addMovieToList).toHaveBeenCalledTimes(2);
    expect(addMovieToList).toHaveBeenLastCalledWith(id, movie, expect.any(Object));
});

it('shows creation guidance for an empty selector and can retry a load failure', async () => {
    getMyLists.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ data: [], meta: { last_page: 1 } });
    selector();
    fireEvent.click(screen.getByRole('button', { name: 'Choose a list' }));
    await screen.findByText('Could not load your lists. Please try again.');
    fireEvent.click(screen.getByRole('button', { name: 'Refresh lists' }));
    expect(await screen.findByRole('link', { name: 'Create or manage lists' })).toHaveAttribute('href', '/my-lists');
});

it('does not offer adds for inactive movies or incomplete profiles', () => {
    const { unmount } = selector({ ...movie, catalog_deactivated: true });
    expect(screen.queryByRole('button', { name: 'Choose a list' })).not.toBeInTheDocument();
    unmount();
    useAuth.mockReturnValue({ user: { id: 1 }, profileCompletion: { complete: false } });
    selector();
    expect(screen.getByRole('link', { name: 'Complete your profile to add to a list' })).toBeInTheDocument();
    expect(getMyLists).not.toHaveBeenCalled();
});

it('opens a local movie without substituting its ID for a provider ID', async () => {
    getLocalMovieDetails.mockResolvedValue({ ...movie, local_movie_id: id, tmdb_id: null, genres: [{ tmdb_id: null, name: 'Student' }], cast: [{ tmdb_person_id: null, name: 'Actor' }] });
    open(`/movies/local/${id}`);
    await screen.findByRole('heading', { name: 'Interstellar' });
    expect(getLocalMovieDetails).toHaveBeenCalledWith(id);
    expect(screen.getByText('Student')).toBeInTheDocument();
    expect(screen.getByText('Actor')).toBeInTheDocument();
});
