import { Navigate, useLocation } from 'react-router-dom';

export default function AdminMovieError({ error, message, onRetry }) {
    const location = useLocation();
    const status = error.response?.status;
    if (status === 401) return <Navigate to="/login" replace state={{ from: location }} />;
    const text = status === 403 ? 'Administrator access is required.'
        : status === 404 ? 'This movie could no longer be found.' : message;

    return (
        <div className="alert alert-danger" role="alert">
            {text}
            {onRetry && status !== 403 && status !== 404 &&
                <button type="button" className="btn btn-outline-danger btn-sm d-block mt-2" onClick={onRetry}>Try again</button>}
        </div>
    );
}
