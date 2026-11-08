
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

// Check form values before sending them to Laravel.
function validateForm(form) {
    const errors = {};

    const name = form.name.trim();
    const email = form.email.trim();
    const password = form.password;

    if (!name) {
        errors.name = 'Name is required.';
    } else if (name.length > 255) {
        errors.name = 'Name cannot exceed 255 characters.';
    }

    if (!email) {
        errors.email = 'Email is required.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        errors.email = 'Enter a valid email address.';
    } else if (email.length > 255) {
        errors.email = 'Email cannot exceed 255 characters.';
    }

    if (!password) {
        errors.password = 'Password is required.';
    } else if (Array.from(password).length < 12) {
        errors.password = 'Password must contain at least 12 characters.';
    } else if (
        new TextEncoder().encode(password).length > 72 ||
        password.includes('\0')
    ) {
        errors.password = 'Password must be at most 72 bytes and contain no NUL characters.';
    }

    if (!form.password_confirmation) {
        errors.password_confirmation = 'Please confirm your password.';
    } else if (form.password_confirmation !== password) {
        errors.password_confirmation = 'Passwords do not match.';
    }

    return errors;
}

function Register() {
    const navigate = useNavigate();

    const [form, setForm] = useState({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const [errors, setErrors] = useState({});
    const [generalError, setGeneralError] = useState('');
    const [loading, setLoading] = useState(false);
    const { signUp } = useAuth();

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

            const user = await signUp(form);

            navigate('/', {
                replace: true,
                state: {
                    registrationSuccess: `Welcome, ${user.name}! Your account has been created.`,
                },
            });
        } catch (error) {
            const status = error.response?.status;

            if (status === 422 && error.response?.data?.errors) {
                const serverErrors = error.response.data.errors;

                const formattedErrors = Object.fromEntries(
                    Object.entries(serverErrors).map(([field, messages]) => [
                        field,
                        Array.isArray(messages) ? messages[0] : String(messages),
                    ])
                );

                setErrors(formattedErrors);
                setGeneralError('Please correct the highlighted fields.');
            } else if (status === 409) {
                setGeneralError('You are already signed in.');
            } else if (status === 419) {
                setGeneralError('Your security session expired. Please try again.');
            } else {
                setGeneralError('Registration failed. Please try again.');
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
                            <h1 className="fs-2 mb-2">Create your account</h1>

                            <p className="text-body-secondary mb-4">
                                Join our movie discovery community.
                            </p>

                            {generalError && (
                                <div className="alert alert-danger" role="alert">
                                    {generalError}
                                </div>
                            )}

                            <form onSubmit={handleSubmit} noValidate>
                                <div className="mb-3 text-start">
                                    <label htmlFor="name" className="form-label">
                                        Full name
                                    </label>
                                    <input
                                        id="name"
                                        name="name"
                                        type="text"
                                        autoComplete="name"
                                        className={`form-control ${errors.name ? 'is-invalid' : ''}`}
                                        value={form.name}
                                        onChange={handleChange}
                                        disabled={loading}
                                    />
                                    {errors.name && (
                                        <div className="invalid-feedback">{errors.name}</div>
                                    )}
                                </div>

                                <div className="mb-3 text-start">
                                    <label htmlFor="email" className="form-label">
                                        Email address
                                    </label>
                                    <input
                                        id="email"
                                        name="email"
                                        type="email"
                                        autoComplete="email"
                                        className={`form-control ${errors.email ? 'is-invalid' : ''}`}
                                        value={form.email}
                                        onChange={handleChange}
                                        disabled={loading}
                                    />
                                    {errors.email && (
                                        <div className="invalid-feedback">{errors.email}</div>
                                    )}
                                </div>

                                <div className="mb-3 text-start">
                                    <label htmlFor="password" className="form-label">
                                        Password
                                    </label>
                                    <input
                                        id="password"
                                        name="password"
                                        type="password"
                                        autoComplete="new-password"
                                        className={`form-control ${errors.password ? 'is-invalid' : ''}`}
                                        value={form.password}
                                        onChange={handleChange}
                                        disabled={loading}
                                    />
                                    <div className="form-text">
                                        At least 12 characters, maximum 72 UTF-8 bytes.
                                    </div>
                                    {errors.password && (
                                        <div className="invalid-feedback d-block">
                                            {errors.password}
                                        </div>
                                    )}
                                </div>

                                <div className="mb-4 text-start">
                                    <label htmlFor="password_confirmation" className="form-label">
                                        Confirm password
                                    </label>
                                    <input
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        type="password"
                                        autoComplete="new-password"
                                        className={`form-control ${errors.password_confirmation ? 'is-invalid' : ''}`}
                                        value={form.password_confirmation}
                                        onChange={handleChange}
                                        disabled={loading}
                                    />
                                    {errors.password_confirmation && (
                                        <div className="invalid-feedback">
                                            {errors.password_confirmation}
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
                                            Creating account...
                                        </>
                                    ) : (
                                        'Create account'
                                    )}
                                </button>
                            </form>

                            <p className="text-center mt-4 mb-0">
                                Already have an account?{' '}
                                <Link to="/login">Log in</Link>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    );
}

export default Register;
