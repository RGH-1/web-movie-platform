import { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import App from '../../src/App.jsx';
import { AuthContext } from '../../src/context/AuthContext.jsx';
import api from '../../src/services/api.js';

const originalAdapter = api.defaults.adapter;
const viewer = { id: '5', name: 'Viewer' };
const author = { id: '8', name: 'List author' };
const list = { id: '9007199254740993', name: 'Weekend favorites', author,
    description: 'A list for Saturday.', movie_count: 1, is_public: true };
const profile = { ...author, reviews: [], public_lists: [{ ...list, movies_count: 1 }] };

afterEach(() => { api.defaults.adapter = originalAdapter; });

function Session({ signedIn }) {
    const [user, setUser] = useState(signedIn ? viewer : null);
    return <AuthContext.Provider value={{
        user, isAuthenticated: !!user, loading: false,
        signIn: async () => { setUser(viewer); return viewer; },
    }}>
        <App />
    </AuthContext.Provider>;
}

function open(path = '/lists/public', signedIn = true) {
    render(<MemoryRouter initialEntries={[path]}><Session signedIn={signedIn} /></MemoryRouter>);
}

function response(config, data, currentPage = 1, lastPage = 1) {
    return { config, data: { data, meta: { current_page: currentPage, last_page: lastPage } }, status: 200 };
}

it('uses the public endpoint, shows list information and links, and loads the next page', async () => {
    const adapter = vi.fn(async (config) => config.params.page === 1
        ? response(config, [list], 1, 2)
        : response(config, [{ ...list, id: '4', name: 'Second list' }], 2, 2));
    api.defaults.adapter = adapter;
    open();

    expect(screen.getByRole('status')).toHaveTextContent('Loading public lists');
    expect(await screen.findByRole('link', { name: list.name })).toHaveAttribute('href', `/lists/${list.id}`);
    expect(screen.getByRole('link', { name: author.name })).toHaveAttribute('href', '/users/8');
    expect(screen.getByText(list.description)).toBeInTheDocument();
    expect(screen.getByText('1 movie')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(await screen.findByText('Second list')).toBeInTheDocument();
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();
    expect(adapter).toHaveBeenLastCalledWith(expect.objectContaining({
        url: '/v1/lists/public', params: { page: 2 },
    }));
});

it('distinguishes a request failure from an empty result and can retry', async () => {
    api.defaults.adapter = vi.fn().mockRejectedValueOnce(new Error('Offline'))
        .mockImplementationOnce(async (config) => response(config, []));
    open();

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load public lists');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('No public movie lists yet.')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
});

it('explains guest access on profiles and returns the viewer to the selected list after login', async () => {
    api.defaults.adapter = vi.fn(async (config) => {
        if (config.url === '/v1/users/8/profile') return response(config, profile);
        if (config.url === `/v1/lists/${list.id}`) return response(config, { ...list, movies: [] });
        throw new Error(`Unexpected URL: ${config.url}`);
    });
    open('/users/8', false);

    const listLink = await screen.findByRole('link', { name: list.name });
    expect(listLink).toHaveAccessibleDescription('Sign in to open a movie list.');
    fireEvent.click(listLink);
    await screen.findByRole('heading', { name: 'Welcome back' });
    for (const provider of ['Google', 'GitHub', 'Facebook']) {
        const providerUrl = new URL(screen.getByRole('link', { name: `Continue with ${provider}` }).href);
        expect(providerUrl.searchParams.get('return_to')).toBe(`/lists/${list.id}`);
    }
    expect(api.defaults.adapter).toHaveBeenCalledTimes(1);
    fireEvent.change(screen.getByLabelText('Email address'), { target: { value: 'viewer@example.com' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'ValidPassword!1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Log in' }));
    expect(await screen.findByRole('heading', { name: list.name })).toBeInTheDocument();
});
