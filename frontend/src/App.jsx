
import { Link, Route, Routes, useLocation } from 'react-router-dom';

import ApiHealthCheck from './components/ApiHealthCheck';
import TmdbIntegrationTest from './components/TmdbIntegrationTest';
import Register from './pages/Register';

import './App.css';

// Existing development homepage
function Home() {
    const location = useLocation();

    const successMessage = location.state?.registrationSuccess;

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

            <div className="mb-4">
                <Link
                    to="/register"
                    className="btn btn-primary"
                >
                    Create an account
                </Link>
            </div>

            <ApiHealthCheck />
            <TmdbIntegrationTest />
        </main>
    );
}

// Temporary page until Task #26
function LoginPlaceholder() {
    return (
        <main className="container py-5">
            <h1 className="fs-2">Login</h1>

            <p className="mb-3">
                The login page will be implemented in Task #26.
            </p>

            <Link to="/register">
                Back to registration
            </Link>
        </main>
    );
}

function App() {
    return (
        <Routes>
            <Route path="/" element={<Home />} />
            <Route path="/register" element={<Register />} />
            <Route path="/login" element={<LoginPlaceholder />} />

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
