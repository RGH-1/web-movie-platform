import { Link } from 'react-router-dom';
import { completionFieldLabel } from '../utils/profileCompletion';

export default function ProfileCompletionNotice({ completion, onEdit, showAction = true }) {
  if (completion?.complete !== false) return null;

  return (
    <aside className="alert alert-warning text-start" role="status" aria-label="Profile completion">
      <h2 className="h5">Complete your profile</h2>
      <p className="mb-2">
        Some required information is missing or invalid. Update it before using
        features that require a complete profile.
      </p>
      {completion.fields.length > 0 && (
        <ul className="mb-3">
          {completion.fields.map((field) => <li key={field}>{completionFieldLabel(field)}</li>)}
        </ul>
      )}
      <p className="mb-3">Your bio and favorite genres are optional.</p>
      {showAction && (onEdit ? (
        <button type="button" className="btn btn-outline-dark" onClick={onEdit}>
          Complete profile
        </button>
      ) : (
        <Link className="btn btn-outline-dark" to="/profile?edit=1">
          Complete profile
        </Link>
      ))}
    </aside>
  );
}
