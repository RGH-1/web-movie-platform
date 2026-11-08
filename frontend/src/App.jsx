import { Link, Route, Routes, useLocation } from 'react-router-dom';

import ApiHealthCheck from './components/ApiHealthCheck';
import TmdbIntegrationTest from './components/TmdbIntegrationTest';

import Register from './pages/Register';
import Login from './pages/Login';
import MovieDiscovery from './pages/MovieDiscovery';

import './App.css';

// Development homepage
function Home() {
    const location = useLocation();

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

            <div className="d-flex justify-content-center gap-2 mb-4">
                <Link to="/register" className="btn btn-primary">
                    Create an account
                </Link>

                <Link to="/login" className="btn btn-outline-primary">
                    Log in
                </Link>

                <Link to="/movies" className="btn btn-outline-primary">
                    Discover Movies
                </Link>
            </div>

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
            <Route path="/movies" element={<MovieDiscovery />} />

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

export default App;
