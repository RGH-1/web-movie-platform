import { afterEach, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import GithubSignIn from '../../src/components/GithubSignIn';

afterEach(() => {
    cleanup();
    window.history.replaceState({}, '', '/');
});

it('navigates through the backend session-based authorization endpoint', () => {
    render(<GithubSignIn />);
    expect(screen.getByRole('link', { name: 'Continue with GitHub' }))
        .toHaveAttribute('href', 'http://127.0.0.1:8000/auth/github/redirect');
});

it('explains email conflicts without offering automatic linking', () => {
    window.history.replaceState({}, '', '/login?oauth_error=email_conflict');
    render(<GithubSignIn />);
    expect(screen.getByRole('alert')).toHaveTextContent('My profile → Link GitHub');
});

it('shows a safe fallback for unexpected callback errors', () => {
    window.history.replaceState({}, '', '/login?oauth_error=private-provider-error');
    render(<GithubSignIn />);
    expect(screen.getByRole('alert')).toHaveTextContent('GitHub sign-in failed. Please try again.');
});
