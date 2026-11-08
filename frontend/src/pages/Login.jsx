
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

// Validate login fields before calling Laravel.
function validateForm(form) {
    const errors = {};

    const email = form.email.trim();
    const password = form.password;

    // Email validation
    if (!email) {
        errors.email = 'Email is required.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.email = 'Enter a valid email address.';
    } else if (email.length > 255) {
        errors.email = 'Email cannot exceed 255 characters.';
    }

    // Password validation
    if (!password) {
        errors.password = 'Password is required.';
    } else if (
        new TextEncoder().encode(password).length > 72 ||
        password.includes('\0')
    ) {
        errors.password =
            'Password must be at most 72 bytes and contain no NUL characters.';
    }

    return errors;
}

function Login() {
    const navigate = useNavigate();

    const [form, setForm] = useState({
        email: '',
        password: '',
    });

    const { signIn } = useAuth();
    const [errors, setErrors] = useState({});
    const [generalError, setGeneralError] = useState('');
    const [loading, setLoading] = useState(false);

    // Update the field the user is typing into.
    function handleChange(event) {
        const { name, value } = event.target;

        setForm((previous) => ({
            ...previous,
            [name]: value,
        }));

        setErrors((previous) => ({
            ...previous,
            [name]: undefined,
        }));

        setGeneralError('');
    }

    // Handle the form submission.
    async function handleSubmit(event) {
        event.preventDefault();

        if (loading) return;

        setGeneralError('');

        const validationErrors = validateForm(form);
        setErrors(validationErrors);

        if (Object.keys(validationErrors).length > 0) {
            return;
        }

        setLoading(true);

        try {
            const user = await signIn(
                form.email.trim().toLowerCase(),
                form.password
            );

            navigate('/', {
                replace: true,
                state: {
                    loginSuccess: `Welcome back, ${user.name}!`,
                },
            });
        } catch (error) {
            const status = error.response?.status;

            if (status === 422 && error.response?.data?.errors) {
                const serverErrors = error.response.data.errors;

                const formattedErrors = Object.fromEntries(
                    Object.entries(serverErrors)
                        .filter(([field]) =>
                            ['email', 'password'].includes(field)
                        )
                        .map(([field, messages]) => [
                            field,
                            Array.isArray(messages)
                                ? messages[0]
                                : String(messages),
                        ])
                );

                setErrors(formattedErrors);
                setGeneralError('Please correct the highlighted fields.');
            } else if (status === 401) {
                setGeneralError('Invalid email or password.');
            } else if (status === 409) {
                setGeneralError('You are already signed in.');
            } else if (status === 419) {
                setGeneralError(
                    'Your security session expired. Please try again.'
                );
            } else if (status === 429) {
                setGeneralError(
                    'Too many login attempts. Please wait before trying again.'
                );
            } else {
                setGeneralError(
                    'Unable to log in. Please try again later.'
                );
            }
        } finally {
            setLoading(false);
        }
    }

    return (
        <main className="container py-4 py-md-5">
            <div className="row justify-content-center">
                <div className="col-12 col-sm-10 col-md-8 col-lg-6">
                    <div className="card shadow-sm border-0">
                        <div className="card-body p-4 p-md-5">
                            <h1 className="fs-2 mb-2">Welcome back</h1>

                            <p className="text-body-secondary mb-4">
                                Log in to your movie discovery account.
                            </p>

                            {generalError && (
                                <div
                                    className="alert alert-danger"
                                    role="alert"
                                >
                                    {generalError}
                                </div>
                            )}

                            <form onSubmit={handleSubmit} noValidate>
                                <div className="mb-3 text-start">
                                    <label
                                        htmlFor="login-email"
                                        className="form-label"
                                    >
                                        Email address
                                    </label>

                                    <input
                                        id="login-email"
                                        name="email"
                                        type="email"
                                        autoComplete="email"
                                        className={`form-control ${errors.email ? 'is-invalid' : ''}`}
                                        value={form.email}
                                        onChange={handleChange}
                                        disabled={loading}
                                    />

                                    {errors.email && (
                                        <div className="invalid-feedback">
                                            {errors.email}
                                        </div>
                                    )}
                                </div>

                                <div className="mb-4 text-start">
                                    <label
                                        htmlFor="login-password"
                                        className="form-label"
                                    >
                                        Password
                                    </label>

                                    <input
                                        id="login-password"
                                        name="password"
                                        type="password"
                                        autoComplete="current-password"
                                        className={`form-control ${errors.password ? 'is-invalid' : ''}`}
                                        value={form.password}
                                        onChange={handleChange}
                                        disabled={loading}
                                    />

                                    {errors.password && (
                                        <div className="invalid-feedback">
                                            {errors.password}
                                        </div>
                                    )}
                                </div>

                                <button
                                    type="submit"
                                    className="btn btn-primary w-100"
                                    disabled={loading}
                                >
                                    {loading ? (
                                        <>
                                            <span
                                                className="spinner-border spinner-border-sm me-2"
                                                aria-hidden="true"
                                            />
                                            Logging in...
                                        </>
                                    ) : (
                                        'Log in'
                                    )}
                                </button>
                            </form>

                            <p className="text-center mt-4 mb-0">
                                Don't have an account?{' '}
                                <Link to="/register">Sign up</Link>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    );
}

export default Login;
