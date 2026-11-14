import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import GithubLink from '../../src/components/GithubLink';
import api from '../../src/services/api';

vi.mock('../../src/services/api', () => ({ default: {
    defaults: { baseURL: 'http://127.0.0.1:8000/api' }, get: vi.fn(), post: vi.fn(),
} }));
afterEach(cleanup);

it('shows the linking form to an account without a connection', () => {
    render(<MemoryRouter><GithubLink linked={false} /></MemoryRouter>);
    expect(screen.getByRole('button', { name: 'Link GitHub' })).toBeVisible();
    expect(screen.getByLabelText('Confirm your movie app password')).toHaveAttribute('type', 'password');
});

it('shows linked status instead of offering to replace the connection', () => {
    render(<MemoryRouter><GithubLink linked /></MemoryRouter>);
    expect(screen.getByRole('status')).toHaveTextContent('GitHub is linked.');
    expect(screen.queryByRole('button', { name: 'Link GitHub' })).not.toBeInTheDocument();
});

it('handles rejected password confirmation and clears the password', async () => {
    api.get.mockResolvedValue({});
    api.post.mockRejectedValue({ response: { status: 422 } });
    render(<MemoryRouter><GithubLink linked={false} /></MemoryRouter>);
    const input = screen.getByLabelText('Confirm your movie app password');
    fireEvent.change(input, { target: { value: 'wrong' } });
    fireEvent.click(screen.getByRole('button', { name: 'Link GitHub' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('password confirmation failed'));
    expect(input).toHaveValue('');
});

it('explains a provider ownership conflict returned from GitHub', () => {
    render(<MemoryRouter initialEntries={['/profile?github_error=already_linked']}><GithubLink linked={false} /></MemoryRouter>);
    expect(screen.getByRole('alert')).toHaveTextContent('No accounts were changed.');
});
