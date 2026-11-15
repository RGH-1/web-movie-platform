import { beforeEach, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import MyListsPage from '../../src/pages/MyListsPage';
import ProtectedRoute from '../../src/components/ProtectedRoute';
import { useAuth } from '../../src/hooks/useAuth';
import { createList, deleteList, getMyLists, updateList } from '../../src/services/lists';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));
vi.mock('../../src/services/lists', () => ({ createList: vi.fn(), deleteList: vi.fn(), getMyLists: vi.fn(), updateList: vi.fn() }));
const list = { id: '9007199254740993', name: 'Weekend', description: null, is_public: false, movie_count: 2 };
const response = (data = [list], page = 1, lastPage = 1) => ({ data, meta: { current_page: page, last_page: lastPage, total: data.length } });
function open() {
    render(<MemoryRouter initialEntries={['/my-lists']}><Routes>
        <Route element={<ProtectedRoute />}><Route path="/my-lists" element={<MyListsPage />} /></Route>
        <Route path="/login" element={<h1>Login destination</h1>} />
    </Routes></MemoryRouter>);
}
beforeEach(() => {
    vi.resetAllMocks();
    useAuth.mockReturnValue({ isAuthenticated: true, loading: false, profileCompletion: { complete: true, fields: [] } });
    getMyLists.mockResolvedValue(response());
});

it('shows loading, metadata, visibility, and movie count', async () => {
    open();
    expect(screen.getByRole('status')).toHaveTextContent('Loading your lists');
    const card = await screen.findByRole('article', { name: 'Weekend' });
    expect(card).toHaveTextContent('Private');
    expect(card).toHaveTextContent('2 movie(s)');
    expect(card).toHaveTextContent('No description.');
});

it('creates a private list and refetches after success', async () => {
    getMyLists.mockResolvedValueOnce(response([])).mockResolvedValueOnce(response());
    createList.mockResolvedValue(list);
    open();
    await screen.findByText('You have no lists yet. Create your first list.');
    fireEvent.click(screen.getByRole('button', { name: 'Create list' }));
    expect(screen.getByRole('checkbox')).not.toBeChecked();
    fireEvent.change(screen.getByLabelText('List name'), { target: { value: ' Weekend ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save list' }));
    await screen.findByText('List saved.');
    expect(createList).toHaveBeenCalledWith({ name: 'Weekend', description: null, is_public: false }, expect.objectContaining({ signal: expect.any(AbortSignal) }));
    await screen.findByRole('article', { name: 'Weekend' });
});

it('prefills editing and persists visibility using the original string ID', async () => {
    updateList.mockResolvedValue({ ...list, is_public: true });
    open();
    await screen.findByRole('article', { name: 'Weekend' });
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    expect(screen.getByLabelText('List name')).toHaveValue('Weekend');
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByRole('button', { name: 'Save list' }));
    await screen.findByText('List saved.');
    expect(updateList).toHaveBeenCalledWith(list.id, expect.objectContaining({ is_public: true }), expect.any(Object));
});

it('requires confirmation, supports cancellation, and recovers after deleting the last list on a page', async () => {
    getMyLists.mockResolvedValueOnce(response([list], 1, 2))
        .mockResolvedValueOnce(response([list], 2, 2))
        .mockResolvedValueOnce(response([], 2, 1))
        .mockResolvedValueOnce(response());
    deleteList.mockResolvedValue(undefined);
    open();
    await screen.findByRole('article', { name: 'Weekend' });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 2');
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    expect(deleteList).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('button', { name: 'Confirm delete' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));
    await screen.findByText('List deleted.');
    await screen.findByRole('article', { name: 'Weekend' });
    expect(deleteList).toHaveBeenCalledWith(list.id, expect.any(Object));
    expect(getMyLists).toHaveBeenLastCalledWith(1, expect.any(Object));
});

it('preserves form values after backend validation errors and allows retry', async () => {
    updateList.mockRejectedValueOnce({ response: { status: 422, data: { errors: { name: ['Backend name error.'] } } } }).mockResolvedValueOnce(list);
    open();
    await screen.findByRole('article', { name: 'Weekend' });
    fireEvent.click(screen.getByRole('button', { name: 'Edit' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save list' }));
    await screen.findByText('Backend name error.');
    expect(screen.getByLabelText('List name')).toHaveValue('Weekend');
    fireEvent.click(screen.getByRole('button', { name: 'Save list' }));
    await screen.findByText('List saved.');
});

it('rejects whitespace-only names locally', async () => {
    open();
    await screen.findByRole('article', { name: 'Weekend' });
    fireEvent.click(screen.getByRole('button', { name: 'Create list' }));
    fireEvent.change(screen.getByLabelText('List name'), { target: { value: '   ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save list' }));
    await screen.findByText('Enter a list name of 1 to 255 characters.');
    expect(createList).not.toHaveBeenCalled();
});

it('retries failed loading and handles failed deletion without removing the card', async () => {
    getMyLists.mockRejectedValueOnce(new Error('network')).mockResolvedValue(response());
    deleteList.mockRejectedValue(new Error('network'));
    open();
    await screen.findByText('Your lists could not be loaded. Please try again.');
    fireEvent.click(screen.getByRole('button', { name: 'Refresh lists' }));
    await screen.findByRole('article', { name: 'Weekend' });
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));
    await screen.findByText('Your changes could not be saved. Please try again.');
    expect(screen.getByRole('article', { name: 'Weekend' })).toBeInTheDocument();
});

it('protects the route and redirects expired sessions', async () => {
    useAuth.mockReturnValue({ isAuthenticated: false, loading: false });
    open();
    expect(screen.getByRole('heading', { name: 'Login destination' })).toBeInTheDocument();
    expect(getMyLists).not.toHaveBeenCalled();
});

it('disables writes for an incomplete profile but still displays owned lists', async () => {
    useAuth.mockReturnValue({ isAuthenticated: true, loading: false, profileCompletion: { complete: false, fields: ['email'] } });
    open();
    await screen.findByRole('article', { name: 'Weekend' });
    expect(screen.getByRole('button', { name: 'Create list' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Edit' })).toBeDisabled();
    expect(screen.getByRole('link', { name: 'Complete profile' })).toHaveAttribute('href', '/profile?edit=1');
});

it('disables duplicate submissions and ignores completion after leaving the page', async () => {
    let finish;
    createList.mockReturnValue(new Promise((resolve) => { finish = resolve; }));
    const { unmount } = render(<MemoryRouter><MyListsPage /></MemoryRouter>);
    await screen.findByRole('article', { name: 'Weekend' });
    fireEvent.click(screen.getByRole('button', { name: 'Create list' }));
    fireEvent.change(screen.getByLabelText('List name'), { target: { value: 'New' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save list' }));
    expect(screen.getByRole('button', { name: 'Saving...' })).toBeDisabled();
    unmount();
    await act(async () => finish(list));
    expect(createList).toHaveBeenCalledTimes(1);
    expect(getMyLists).toHaveBeenCalledTimes(1);
});

it('redirects to login if the list request returns 401', async () => {
    getMyLists.mockRejectedValue({ response: { status: 401 } });
    open();
    await screen.findByRole('heading', { name: 'Login destination' });
});

it('shows profile guidance when the server rejects a write for an incomplete profile', async () => {
    createList.mockRejectedValue({ response: { status: 403, data: { code: 'PROFILE_INCOMPLETE', incomplete_fields: ['email'] } } });
    open();
    await screen.findByRole('article', { name: 'Weekend' });
    fireEvent.click(screen.getByRole('button', { name: 'Create list' }));
    fireEvent.change(screen.getByLabelText('List name'), { target: { value: 'New' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save list' }));
    await screen.findByRole('link', { name: 'Complete profile' });
    expect(screen.getByRole('button', { name: 'Save list' })).toBeDisabled();
});
