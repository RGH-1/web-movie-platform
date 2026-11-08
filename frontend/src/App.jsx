
import { useState } from 'react';
import { Link, Route, Routes, useLocation } from 'react-router-dom';

import ApiHealthCheck from './components/ApiHealthCheck';
import TmdbIntegrationTest from './components/TmdbIntegrationTest';
import ProtectedRoute from './components/ProtectedRoute';
import ProfileCompletionNotice from './components/ProfileCompletionNotice';

import Register from './pages/Register';
import Login from './pages/Login';
import ProfilePage from './pages/ProfilePage';
import MovieDiscovery from './pages/MovieDiscovery';
import MovieDetailNavigation from './pages/MovieDetailNavigation';

import { useAuth } from './hooks/useAuth';

import './App.css';

// Development homepage
function Home() {
    const location = useLocation();
    const { isAuthenticated, profileCompletion } = useAuth();

    const successMessage =
        location.state?.registrationSuccess ||
        location.state?.loginSuccess;

    return (
        <main className="container py-5">
            <h1>Movie Platform</h1>
            <p className="mb-4">Development Environment</p>

            {successMessage && (
                <div
                    className="alert alert-success"
                    role="status"
                >
                    {successMessage}
                </div>
            )}

            {isAuthenticated && <ProfileCompletionNotice completion={profileCompletion} />}

            <div className="d-flex justify-content-center gap-2 mb-4 flex-wrap">
                <Link to="/movies" className="btn btn-outline-primary">
                    Discover Movies
                </Link>
            </div>

            <AuthControls />

            <ApiHealthCheck />
            <TmdbIntegrationTest />
        </main>
    );
}

function App() {
    return (
        <Routes>
            <Route path="/" element={<Home />} />
            <Route path="/register" element={<Register />} />
            <Route path="/login" element={<Login />} />
            <Route element={<ProtectedRoute />}>
                <Route path="/profile" element={<ProfilePage />} />
                <Route path="/movies" element={<MovieDiscovery />} />
                <Route path="/movies/:tmdbId" element={<MovieDetailNavigation />} />
            </Route>

            <Route
                path="*"
                element={
                    <main className="container py-5">
                        <h1 className="fs-2">Page not found</h1>
                        <Link to="/">Go home</Link>
                    </main>
                }
            />
        </Routes>
    );
}

function AuthControls() {
    const { user, isAuthenticated, loading, signOut } = useAuth();

    const [loggingOut, setLoggingOut] = useState(false);
    const [error, setError] = useState('');

    async function handleLogout() {
        setLoggingOut(true);
        setError('');

        try {
            await signOut();
        } catch {
            setError('Logout failed. Please try again.');
        } finally {
            setLoggingOut(false);
        }
    }

    if (loading) {
        return <p>Checking authentication...</p>;
    }

    return (
        <div className="mt-3">
            {isAuthenticated ? (
                <>
                    <p>Logged in as {user.name}</p>
                    <Link
                        to="/profile"
                        className="btn btn-outline-primary me-2"
                    >
                        My profile
                    </Link>

                    <button
                        type="button"
                        className="btn btn-danger"
                        onClick={handleLogout}
                        disabled={loggingOut}
                    >
                        {loggingOut ? 'Logging out...' : 'Log out'}
                    </button>
                </>
            ) : (
                <>
                    <Link
                        to="/login"
                        className="btn btn-primary me-2"
                    >
                        Log in
                    </Link>
                    <Link
                        to="/register"
                        className="btn btn-outline-primary"
                    >
                        Sign up
                    </Link>
                </>
            )}

            {error && (
                <div className="alert alert-danger mt-3" role="alert">
                    {error}
                </div>
            )}
        </div>
    );
}

export default App;
