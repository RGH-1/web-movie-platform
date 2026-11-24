import { Link, NavLink } from 'react-router-dom';

export default function AdminNavigation() {
    return (
        <nav aria-label="Administrator navigation" className="d-flex flex-wrap gap-3">
            <Link to="/">Back to home</Link>
            <NavLink to="/admin/dashboard">Admin dashboard</NavLink>
            <NavLink to="/admin/users">User management</NavLink>
            <NavLink to="/admin/movies">Movie management</NavLink>
            <NavLink to="/admin/reviews">Review moderation</NavLink>
        </nav>
    );
}
