import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import PasswordPage from '../../src/pages/PasswordPage';
import { useAuth } from '../../src/hooks/useAuth';
import { getProfile } from '../../src/services/profile';
import { changePassword, requestPasswordReset, resetPassword } from '../../src/services/passwords';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));
vi.mock('../../src/services/profile', () => ({ getProfile: vi.fn() }));
vi.mock('../../src/services/passwords', () => ({ changePassword: vi.fn(), requestPasswordReset: vi.fn(), resetPassword: vi.fn() }));
const setUser = vi.fn();
function Destination() {
    const location = useLocation();
    return <><h1>Login destination</h1><p>{location.state?.passwordSuccess}</p></>;
}
function CurrentUrl() { const location = useLocation(); return <output aria-label="Current URL">{location.pathname}{location.search}</output>; }
function open(mode, url = mode === 'change' ? '/account/password' : mode === 'reset' ? '/reset-password?token=dummy-test-token&email=sam%40example.com' : '/forgot-password') {
    return render(<MemoryRouter initialEntries={[url]}><CurrentUrl /><Routes>
        <Route path="/account/password" element={<PasswordPage mode="change" />} />
        <Route path="/forgot-password" element={<PasswordPage mode="forgot" />} />
        <Route path="/reset-password" element={<PasswordPage mode="reset" />} />
        <Route path="/login" element={<Destination />} />
    </Routes></MemoryRouter>);
}
function fillPassword() {
    fireEvent.change(screen.getByLabelText('New app password'), { target: { value: 'StrongNew!Pass123' } });
    fireEvent.change(screen.getByLabelText('Confirm new app password'), { target: { value: 'StrongNew!Pass123' } });
}
beforeEach(() => {
    vi.resetAllMocks();
    useAuth.mockReturnValue({ setUser });
    getProfile.mockResolvedValue({ email: 'sam@example.com', has_local_password: true });
});
afterEach(() => vi.useRealTimers());

it('changes an app password, then clears local authentication and returns to login', async () => {
    changePassword.mockResolvedValue(undefined);
    open('change');
    await screen.findByLabelText('Current app password');
    fireEvent.change(screen.getByLabelText('Current app password'), { target: { value: ' Old!Pass12345 ' } });
    fillPassword();
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }));
    await screen.findByRole('heading', { name: 'Login destination' });
    expect(changePassword).toHaveBeenCalledWith({ current_password: ' Old!Pass12345 ', password: 'StrongNew!Pass123', password_confirmation: 'StrongNew!Pass123' }, expect.any(Object));
    expect(setUser).toHaveBeenCalledWith(null);
    expect(screen.getByText('Password changed. Please sign in again.')).toBeInTheDocument();
});

it('guides a social-only account to setup without asking for the provider password', async () => {
    getProfile.mockResolvedValue({ email: 'sam@example.com', has_local_password: false });
    open('change');
    expect(await screen.findByRole('link', { name: 'Email me a password setup link' })).toHaveAttribute('href', '/forgot-password?setup=1');
    expect(screen.queryByLabelText('Current app password')).not.toBeInTheDocument();
});

it('does not offer email recovery to a social account with no email', async () => {
    getProfile.mockResolvedValue({ email: null, has_local_password: false });
    open('change');
    await screen.findByText('Your account has no email address, so email recovery is unavailable. Continue using your social login.');
    expect(screen.queryByRole('link', { name: 'Email me a password setup link' })).not.toBeInTheDocument();
});

it('gives the same generic forgot-password success without exposing account existence', async () => {
    requestPasswordReset.mockResolvedValue(undefined);
    open('forgot');
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: ' Sam@Example.com ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send reset link' }));
    await screen.findByText('If an account exists for this email, a password reset link has been sent.');
    expect(requestPasswordReset).toHaveBeenCalledWith('sam@example.com', expect.any(Object));
    expect(screen.queryByRole('button', { name: 'Send reset link' })).not.toBeInTheDocument();
});

it('captures token/email, removes them from the URL, and resets with the exact link values', async () => {
    resetPassword.mockResolvedValue(undefined);
    open('reset');
    expect(screen.getByLabelText('Current URL')).toHaveTextContent('/reset-password');
    expect(screen.getByLabelText('Current URL')).not.toHaveTextContent('dummy-test-token');
    expect(screen.getByLabelText('Email')).toHaveValue('sam@example.com');
    expect(screen.getByLabelText('Email')).toHaveAttribute('readonly');
    fillPassword();
    fireEvent.click(screen.getByRole('button', { name: 'Save app password' }));
    await screen.findByRole('heading', { name: 'Login destination' });
    expect(resetPassword).toHaveBeenCalledWith({ email: 'sam@example.com', token: 'dummy-test-token', password: 'StrongNew!Pass123', password_confirmation: 'StrongNew!Pass123' }, expect.any(Object));
    expect(setUser).toHaveBeenCalledWith(null);
});

it.each(['/reset-password', '/reset-password?token=abc', '/reset-password?email=sam%40example.com&token=a&token=b'])(
    'rejects incomplete or ambiguous reset URL %s without submitting', (url) => {
        open('reset', url);
        expect(screen.getByRole('link', { name: 'Request a new link' })).toHaveAttribute('href', '/forgot-password');
        expect(screen.queryByLabelText('New app password')).not.toBeInTheDocument();
        expect(resetPassword).not.toHaveBeenCalled();
    }
);

it('handles expired/reused reset tokens without displaying or retaining password inputs', async () => {
    resetPassword.mockRejectedValue({ response: { status: 422, data: { errors: { token: ['Invalid token.'] } } } });
    open('reset');
    fillPassword();
    fireEvent.click(screen.getByRole('button', { name: 'Save app password' }));
    await screen.findByRole('link', { name: 'Request a new link' });
    expect(screen.queryByLabelText('New app password')).not.toBeInTheDocument();
});

it('shows validation while clearing sensitive fields after a failed change', async () => {
    changePassword.mockRejectedValue({ response: { status: 422, data: { errors: { current_password: ['Incorrect app password.'] } } } });
    open('change');
    await screen.findByLabelText('Current app password');
    fireEvent.change(screen.getByLabelText('Current app password'), { target: { value: 'Wrong' } });
    fillPassword();
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }));
    await screen.findByText('Incorrect app password.');
    expect(screen.getByLabelText('Current app password')).toHaveValue('');
    expect(screen.getByLabelText('New app password')).toHaveValue('');
});

it('blocks weak/mismatched passwords before submitting', () => {
    open('reset');
    fireEvent.change(screen.getByLabelText('New app password'), { target: { value: 'weak' } });
    fireEvent.change(screen.getByLabelText('Confirm new app password'), { target: { value: 'different' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save app password' }));
    expect(screen.getByText('The passwords must match.')).toBeInTheDocument();
    expect(resetPassword).not.toHaveBeenCalled();
});

it('honors rate-limit cooldown and automatically enables retry', async () => {
    requestPasswordReset.mockRejectedValue({ response: { status: 429, headers: { 'retry-after': '2' } } });
    vi.useFakeTimers();
    open('forgot');
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'sam@example.com' } });
    await act(async () => { fireEvent.click(screen.getByRole('button', { name: 'Send reset link' })); });
    expect(screen.getByText('Too many requests. Please wait before trying again.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Send reset link' })).toBeDisabled();
    await act(async () => { vi.advanceTimersByTime(2000); });
    expect(screen.getByRole('button', { name: 'Send reset link' })).toBeEnabled();
});

it('offers a safe retry for CSRF failures without logging request values', async () => {
    requestPasswordReset.mockRejectedValue({ response: { status: 419 } });
    open('forgot');
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'sam@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send reset link' }));
    await screen.findByText('Your security session expired. Please try again to refresh it.');
    expect(screen.getByLabelText('Email')).toHaveValue('sam@example.com');
});

it('uses setup wording from social password settings while keeping the same recovery API', async () => {
    getProfile.mockResolvedValue({ email: 'sam@example.com', has_local_password: false });
    requestPasswordReset.mockResolvedValue(undefined);
    open('change');
    fireEvent.click(await screen.findByRole('link', { name: 'Email me a password setup link' }));
    expect(screen.getByRole('heading', { name: 'Set up an app password' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Forgot your app password?' })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'sam@example.com' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send setup link' }));
    await screen.findByText('If an account exists for this email, a password setup link has been sent.');
    expect(requestPasswordReset).toHaveBeenCalledWith('sam@example.com', expect.any(Object));
});
