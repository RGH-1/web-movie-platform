import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api from '../services/api';

export default function GithubLink({ linked }) {
    const [params] = useSearchParams();
    const [password, setPassword] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const callbackError = params.get('github_error');
    const messages = {
        already_linked: 'This GitHub account or your movie account already has a different connection. No accounts were changed.',
        denied: 'GitHub authorization was cancelled. Your account was not changed.',
        failed: 'GitHub could not be linked. Please try again.',
    };

    async function link(event) {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href);
            const response = await api.post('/v1/auth/github/link', { current_password: password });
            setPassword('');
            window.location.assign(response.data.data.url);
        } catch (failure) {
            setPassword('');
            const status = failure.response?.status;
            setError(status === 422 ? 'The password confirmation failed. Enter your current movie app password.'
                : status === 401 || status === 419 ? 'Your session expired. Sign in again before linking GitHub.'
                : status === 429 ? 'Too many attempts. Please wait before trying again.'
                : 'GitHub could not be linked. Please try again later.');
            setBusy(false);
        }
    }

    return (
        <section className="card shadow-sm mt-4" aria-labelledby="github-heading">
            <div className="card-body p-3 p-md-4">
                <h2 id="github-heading" className="h5">GitHub sign-in</h2>
                {callbackError && <p role="alert" className="alert alert-danger">{messages[callbackError] || messages.failed}</p>}
                {linked ? <p role="status" className="mb-0">GitHub is linked. You can use Continue with GitHub next time you sign in.</p> : (
                    <form onSubmit={link}>
                        <p>Connect your GitHub account so you can use it to sign in to this account.</p>
                        {error && <p role="alert" className="alert alert-danger">{error}</p>}
                        <label htmlFor="github-link-password" className="form-label">Confirm your movie app password</label>
                        <input id="github-link-password" type="password" autoComplete="current-password"
                            required className="form-control mb-3" value={password}
                            onChange={(event) => setPassword(event.target.value)} disabled={busy} />
                        <button className="btn btn-outline-primary" type="submit" disabled={busy}>
                            {busy ? 'Connecting…' : 'Link GitHub'}
                        </button>
                    </form>
                )}
            </div>
        </section>
    );
}
