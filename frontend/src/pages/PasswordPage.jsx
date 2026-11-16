import { useEffect, useRef, useState } from 'react';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { getProfile } from '../services/profile';
import { changePassword, requestPasswordReset, resetPassword } from '../services/passwords';
import { validateNewPassword, validRecoveryEmail } from '../utils/passwordForm';

export default function PasswordPage({ mode }) {
    return <PasswordForm key={mode} mode={mode} />;
}

function PasswordForm({ mode }) {
    const location = useLocation();
    const navigate = useNavigate();
    const { setUser } = useAuth();
    const changing = mode === 'change';
    const resetting = mode === 'reset';
    const settingUp = mode === 'forgot' && new URLSearchParams(location.search).get('setup') === '1';
    const [reset, setReset] = useState(() => {
        const params = new URLSearchParams(location.search);
        const email = params.get('email') ?? '';
        const token = params.get('token') ?? '';
        return { email, token, valid: params.getAll('email').length === 1 && params.getAll('token').length === 1 &&
            validRecoveryEmail(email) && token.length > 0 && token.length <= 255 };
    });
    const [profile, setProfile] = useState(null);
    const [loading, setLoading] = useState(changing);
    const [attempt, setAttempt] = useState(0);
    const [form, setForm] = useState({ email: resetting ? reset.email : '', current_password: '', password: '', password_confirmation: '' });
    const [errors, setErrors] = useState({});
    const [error, setError] = useState('');
    const [success, setSuccess] = useState(false);
    const [busy, setBusy] = useState(false);
    const [retryAt, setRetryAt] = useState(null);
    const [expiredSession, setExpiredSession] = useState(false);
    const operation = useRef(null);

    useEffect(() => () => operation.current?.abort(), []);
    useEffect(() => {
        if (resetting && location.search) navigate(location.pathname, { replace: true });
    }, [resetting, location.search, location.pathname, navigate]);
    useEffect(() => {
        if (!retryAt) return;
        const timer = setTimeout(() => setRetryAt(null), Math.max(0, retryAt - Date.now()));
        return () => clearTimeout(timer);
    }, [retryAt]);
    useEffect(() => {
        if (!changing) return;
        const controller = new AbortController();
        getProfile({ signal: controller.signal }).then((data) => {
            if (controller.signal.aborted) return;
            if (typeof data?.has_local_password !== 'boolean') throw new Error('Unavailable profile.');
            setProfile(data);
        }).catch((err) => {
            if (controller.signal.aborted) return;
            if (err.response?.status === 401) setExpiredSession(true);
            else setError('Password settings could not be loaded. Please try again.');
        }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [changing, attempt]);

    async function submit(event) {
        event.preventDefault();
        if (busy || retryAt || (resetting && !reset.valid)) return;
        const invalid = changing || resetting ? validateNewPassword(form.password, form.password_confirmation) : {};
        const email = form.email.trim().toLowerCase();
        if (!changing && !validRecoveryEmail(email)) invalid.email = ['Enter a valid email address.'];
        if (changing && !form.current_password) invalid.current_password = ['Enter your current movie-platform password.'];
        setErrors(invalid);
        setError('');
        if (Object.keys(invalid).length) return;
        const controller = new AbortController();
        operation.current = controller;
        setBusy(true);
        try {
            if (changing) await changePassword({ current_password: form.current_password, password: form.password, password_confirmation: form.password_confirmation }, { signal: controller.signal });
            else if (resetting) await resetPassword({ email: reset.email, token: reset.token, password: form.password, password_confirmation: form.password_confirmation }, { signal: controller.signal });
            else await requestPasswordReset(email, { signal: controller.signal });
            if (controller.signal.aborted) return;
            setForm((current) => ({ ...current, current_password: '', password: '', password_confirmation: '' }));
            if (!changing && !resetting) setSuccess(true);
            else {
                setReset({ email: '', token: '', valid: false });
                navigate('/login', { replace: true, state: { passwordSuccess: changing ? 'Password changed. Please sign in again.' : 'Password reset. Please sign in again.' } });
                setUser(null);
            }
        } catch (err) {
            if (controller.signal.aborted) return;
            const status = err.response?.status;
            if (status === 422) {
                const fields = err.response.data?.errors ?? {};
                setErrors(Object.fromEntries(Object.entries(fields).filter(([key]) => ['email', 'current_password', 'password', 'password_confirmation'].includes(key))));
                if (resetting && fields.token) setReset({ email: '', token: '', valid: false });
                else setError('Please check the form and try again.');
            } else if (status === 401 && changing) setExpiredSession(true);
            else if (status === 429) {
                const seconds = Number(err.response?.headers?.['retry-after']);
                setRetryAt(Date.now() + (Number.isSafeInteger(seconds) && seconds > 0 && seconds <= 3600 ? seconds : 60) * 1000);
                setError('Too many requests. Please wait before trying again.');
            } else setError(status === 419 ? 'Your security session expired. Please try again to refresh it.' : 'The request could not be completed. Please try again.');
        } finally {
            if (!controller.signal.aborted) {
                setForm((current) => ({ ...current, current_password: '', password: '', password_confirmation: '' }));
                setBusy(false);
            }
        }
    }

    function field(name, label, type, autoComplete) {
        return <div className="mb-3">
            <label htmlFor={`password-${name}`} className="form-label">{label}</label>
            <input id={`password-${name}`} type={type} autoComplete={autoComplete} className="form-control"
                value={form[name]} required readOnly={resetting && name === 'email'}
                aria-invalid={!!errors[name]} aria-describedby={errors[name] ? `password-${name}-error` : undefined}
                onChange={(event) => { setForm({ ...form, [name]: event.target.value }); setErrors({ ...errors, [name]: undefined }); }} />
            {errors[name] && <p id={`password-${name}-error`} className="text-danger" role="alert">{errors[name].join(' ')}</p>}
        </div>;
    }

    if (expiredSession) return <Navigate to="/login" replace state={{ from: location }} />;
    const title = changing ? 'Password settings' : resetting ? 'Choose your app password' : settingUp ? 'Set up an app password' : 'Forgot your app password?';
    const showForm = changing ? profile?.has_local_password === true : resetting ? reset.valid : !success;
    return (
        <main className="container py-4 py-md-5 text-start">
            <div className="row justify-content-center"><div className="col-12 col-md-8 col-lg-6">
                <h1 className="h2">{title}</h1>
                <p>This manages your movie-platform password. Your Google, Facebook, or GitHub password stays with that provider.</p>
                {loading && <p role="status">Loading password settings...</p>}
                {error && <div className="alert alert-danger" role="alert">{error}</div>}
                {changing && !loading && !profile && <button className="btn btn-outline-primary" onClick={() => { setLoading(true); setError(''); setAttempt(value => value + 1); }}>Try again</button>}
                {changing && profile?.has_local_password === false && <div className="alert alert-info">
                    <p>You have not set a movie-platform password. Continue using social login, or verify your account email to set an app password.</p>
                    {profile.email ? <Link to="/forgot-password?setup=1">Email me a password setup link</Link>
                        : <p>Your account has no email address, so email recovery is unavailable. Continue using your social login.</p>}
                </div>}
                {resetting && !reset.valid && <div className="alert alert-warning" role="alert">This reset link is invalid or has expired. <Link to="/forgot-password">Request a new link</Link></div>}
                {settingUp && <p>Enter your account email to receive a secure link for choosing a movie-platform password. Your social login will remain available.</p>}
                {success && <p className="alert alert-success" role="status">{settingUp
                    ? 'If an account exists for this email, a password setup link has been sent.'
                    : 'If an account exists for this email, a password reset link has been sent.'}</p>}
                {showForm && <form onSubmit={submit} noValidate className="card"><div className="card-body">
                    <fieldset disabled={busy || retryAt !== null}>
                        {!changing && field('email', 'Email', 'email', 'email')}
                        {changing && field('current_password', 'Current app password', 'password', 'current-password')}
                        {(changing || resetting) && <>
                            <p className="small text-body-secondary">Use at least 12 characters, uppercase and lowercase letters, a number, and a symbol; at most 72 UTF-8 bytes. Spaces are preserved.</p>
                            {field('password', 'New app password', 'password', 'new-password')}
                            {field('password_confirmation', 'Confirm new app password', 'password', 'new-password')}
                            <p>After saving, your movie-platform sessions will be signed out. Social login remains available.</p>
                        </>}
                        <button type="submit" className="btn btn-primary">{busy ? 'Submitting...' : changing ? 'Change password' : resetting ? 'Save app password' : settingUp ? 'Send setup link' : 'Send reset link'}</button>
                    </fieldset>
                </div></form>}
                {retryAt && <p role="status">Please wait. The form will become available automatically.</p>}
                {changing && profile?.has_local_password && <p className="mt-3"><Link to="/forgot-password">Forgot your app password?</Link></p>}
                <p className="mt-3"><Link to={changing ? '/profile' : '/login'}>{changing ? 'Back to profile' : 'Back to login'}</Link></p>
            </div></div>
        </main>
    );
}
