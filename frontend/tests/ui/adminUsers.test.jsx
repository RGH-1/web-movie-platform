import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import App from '../../src/App';
import { useAuth } from '../../src/hooks/useAuth';
import api from '../../src/services/api';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));

const originalAdapter = api.defaults.adapter;
const admin = { id: '4', name: 'Site admin', email: 'admin@example.com', status: 'enabled', is_admin: true };
const member = { id: '9007199254740993', name: 'Morgan', email: 'morgan@example.com', status: 'enabled', is_admin: false };

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.clearAllMocks();
});

function open(isAdmin = true) {
    useAuth.mockReturnValue({ isAuthenticated: true, user: { ...admin, can_access_admin: isAdmin }, loading: false });
    render(<MemoryRouter initialEntries={['/admin/users']}><App /></MemoryRouter>);
}

function response(config, data, status = 200) {
    return { config, data, status, headers: {} };
}

it('blocks regular users before requesting private account data', () => {
    api.defaults.adapter = vi.fn();
    open(false);
    expect(screen.getByRole('alert')).toHaveTextContent('Administrator access is required.');
    expect(api.defaults.adapter).not.toHaveBeenCalled();
});

it('searches and paginates, protects admin rows, and reflects both status changes', async () => {
    const requests = vi.fn(async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.method === 'patch') {
            const { status } = JSON.parse(config.data);
            expect(config.url).toBe(`/v1/admin/users/${member.id}/status`);
            return response(config, { data: { ...member, status } });
        }
        const { search, page } = config.params;
        return response(config, {
            data: search ? [member] : [admin],
            meta: { current_page: page, last_page: search ? 2 : 1 },
        });
    });
    api.defaults.adapter = requests;
    open();
    expect(screen.getByRole('status')).toHaveTextContent('Loading users');
    const protectedRow = await screen.findByRole('row', { name: /Site admin/ });
    expect(within(protectedRow).getByText('Protected')).toBeInTheDocument();
    expect(within(protectedRow).queryByRole('button')).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Search by name or email'), { target: { value: 'Morgan' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await screen.findByRole('row', { name: /Morgan/ });
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    await screen.findByText('Page 2 of 2');
    expect(requests).toHaveBeenLastCalledWith(expect.objectContaining({ params: { page: 2, search: 'Morgan' } }));

    fireEvent.click(screen.getByRole('button', { name: 'Disable Morgan (morgan@example.com)' }));
    expect(requests.mock.calls.some(([request]) => request.method === 'patch')).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm disable' }));
    expect(await screen.findByText('Account disabled.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Enable Morgan (morgan@example.com)' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm enable' }));
    expect(await screen.findByText('Account enabled.')).toBeInTheDocument();

    const writes = requests.mock.calls.flatMap(([{ method, url }]) => method === 'patch' || url.endsWith('/sanctum/csrf-cookie') ? [method] : []);
    expect(writes).toEqual(['get', 'patch', 'get', 'patch']);
});

it('shows an error, retries the search, and distinguishes an empty result', async () => {
    api.defaults.adapter = vi.fn().mockRejectedValueOnce(new Error('Offline')).mockImplementationOnce(async (config) => (
        response(config, { data: [], meta: { current_page: 1, last_page: 1 } })
    ));
    open();
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load users.');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('No users found.')).toBeInTheDocument();
});
