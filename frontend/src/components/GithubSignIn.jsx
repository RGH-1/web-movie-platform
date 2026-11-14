import api from '../services/api';

export default function GithubSignIn() {
    const error = new URLSearchParams(window.location.search).get('oauth_error');
    const messages = {
        denied: 'GitHub authorization was cancelled. You can try again.',
        invalid_state: 'Your GitHub sign-in session expired. Please try again.',
        email_conflict: 'An account already uses this email. Sign in with your movie app email and password, then open My profile → Link GitHub.',
        unavailable: 'GitHub sign-in is not configured yet.',
        failed: 'GitHub sign-in failed. Please try again.',
    };
    const href = new URL('/auth/github/redirect', api.defaults.baseURL).href;

    return (
        <div className="mb-4">
            {error && <p role="alert" className="alert alert-danger">{messages[error] || messages.failed}</p>}
            <a className="btn btn-outline-secondary w-100" href={href}>Continue with GitHub</a>
        </div>
    );
}
