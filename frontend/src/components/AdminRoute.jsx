import { Link, Outlet } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

export default function AdminRoute() {
    const { user } = useAuth();

    if (user?.can_access_admin === true) return <Outlet />;

    return (
        <main className="container py-4 text-start">
            <Link to="/">Back to home</Link>
            <h1 className="h2 mt-3">Administrator access</h1>
            <p role="alert" className="alert alert-warning">Administrator access is required.</p>
        </main>
    );
}
