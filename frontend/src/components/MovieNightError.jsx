import { Navigate, useLocation } from 'react-router-dom';
import ProfileCompletionNotice from './ProfileCompletionNotice';
import { completionFromError } from '../utils/profileCompletion';

export default function MovieNightError({ error, message, onRetry }) {
    const location = useLocation();
    const status = error.response?.status;
    if (status === 401) return <Navigate to="/login" replace state={{ from: location }} />;

    const completion = completionFromError(error);
    if (completion) return <ProfileCompletionNotice completion={completion} />;

    let text = message;
    if (status === 404) text = 'Movie night not found or unavailable to your account.';
    if (status === 403) text = 'This action is no longer available for your account.';
    if (status === 419) text = 'Your session could not be verified. Refresh the page and try again.';

    return (
        <div className="alert alert-danger" role="alert">
            {text}
            {onRetry && status !== 404 && (
                <button type="button" className="btn btn-outline-danger btn-sm d-block mt-2" onClick={onRetry}>
                    Try again
                </button>
            )}
        </div>
    );
}
