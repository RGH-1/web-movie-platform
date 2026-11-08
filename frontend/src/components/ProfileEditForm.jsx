import { useEffect, useRef, useState } from 'react';
import { updateProfile } from '../services/profile';
import {
  GENRES, profileToForm, emailIsChanging, validateProfileForm, buildProfileUpdate,
} from '../utils/profileForm';

function FieldErrors({ field, errors }) {
  const messages = Object.entries(errors)
    .filter(([key]) => key === field || key.startsWith(field + '.'))
    .flatMap(([, value]) => Array.isArray(value) ? value : [String(value)]);

  if (!messages.length) return null;
  return (
    <div id={field + '-errors'} className="invalid-feedback d-block">
      {messages.map((message, index) => <div key={index}>{message}</div>)}
    </div>
  );
}

export default function ProfileEditForm({ profile, onSaved, onCancel, onUnauthenticated }) {
  const [form, setForm] = useState(() => profileToForm(profile));
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState('');
  const [saving, setSaving] = useState(false);
  const pending = useRef(null);
  const errorSummary = useRef(null);
  const changingEmail = emailIsChanging(form, profile);

  useEffect(() => () => pending.current?.abort(), []);

  function change(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
    setErrors((current) => Object.fromEntries(
      Object.entries(current).filter(([key]) => key !== field && !key.startsWith(field + '.')),
    ));
    setMessage('');
  }

  function hasError(field) {
    return Object.keys(errors).some((key) => key === field || key.startsWith(field + '.'));
  }

  function inputProps(field) {
    return {
      id: 'profile-' + field,
      name: field,
      value: form[field],
      onChange: (event) => change(field, event.target.value),
      className: 'form-control' + (hasError(field) ? ' is-invalid' : ''),
      'aria-invalid': hasError(field),
      'aria-describedby': hasError(field) ? field + '-errors' : undefined,
    };
  }

  async function submit(event) {
    event.preventDefault();
    if (pending.current) return;

    const validation = validateProfileForm(form, profile);
    setErrors(validation);
    if (Object.keys(validation).length) {
      setMessage('Please correct the highlighted fields.');
      errorSummary.current?.focus();
      return;
    }

    const controller = new AbortController();
    pending.current = controller;
    setSaving(true);
    setMessage('');

    try {
      const saved = await updateProfile(buildProfileUpdate(form, profile), {
        signal: controller.signal,
      });
      if (!controller.signal.aborted) onSaved(saved);
    } catch (error) {
      if (controller.signal.aborted) return;
      const status = error.response?.status;
      if (status === 401) {
        onUnauthenticated();
        return;
      }
      if (status === 422) {
        setErrors(error.response.data?.errors ?? {});
        setMessage('Please correct the highlighted fields.');
      } else if (status === 419) {
        setMessage('Your security session expired. Submit again to refresh it, or sign in again.');
      } else {
        setMessage('We couldn’t confirm your changes were saved. Your entries are still here. Check your connection and reload your profile before retrying.');
      }
      // Do not retain password confirmations after any failed attempt.
      setForm((current) => ({ ...current, current_password: '' }));
      errorSummary.current?.focus();
    } finally {
      if (!controller.signal.aborted) {
        pending.current = null;
        setSaving(false);
      }
    }
  }

  return (
    <form onSubmit={submit} noValidate aria-labelledby="edit-profile-heading" aria-busy={saving}>
      <h2 id="edit-profile-heading" className="h5 mb-3">Edit profile</h2>
      <div ref={errorSummary} tabIndex={-1}>
        {message && <p className="alert alert-danger" role="alert">{message}</p>}
      </div>

      <fieldset disabled={saving}>
        <div className="mb-3">
          <label htmlFor="profile-name" className="form-label">Name</label>
          <input {...inputProps('name')} type="text" autoComplete="name" required />
          <FieldErrors field="name" errors={errors} />
        </div>

        <div className="mb-3">
          <label htmlFor="profile-email" className="form-label">Email</label>
          <input {...inputProps('email')} type="email" autoComplete="email" required />
          <FieldErrors field="email" errors={errors} />
        </div>

        {changingEmail && (
          <div className="mb-3">
            <label htmlFor="profile-current_password" className="form-label">
              Current password
            </label>
            <input {...inputProps('current_password')} type="password"
              autoComplete="current-password" required />
            <p className="form-text">Required to confirm a change to your sign-in email.</p>
            <FieldErrors field="current_password" errors={errors} />
          </div>
        )}

        <div className="mb-3">
          <label htmlFor="profile-bio" className="form-label">Bio (optional)</label>
          <textarea {...inputProps('bio')} rows={4} />
          <p className="form-text">Up to 500 characters. Leave blank to clear your bio.</p>
          <FieldErrors field="bio" errors={errors} />
        </div>

        <fieldset className="mb-4" aria-describedby="genres-help favorite_genres-errors">
          <legend className="fs-6">Favorite genres (optional)</legend>
          <p id="genres-help" className="form-text mt-0">
            Choose up to five. Clear all selections to remove your preferences.
          </p>
          <div className="row g-2">
            {Object.entries(GENRES).map(([slug, label]) => (
              <div key={slug} className="col-12 col-sm-6">
                <div className="form-check">
                  <input type="checkbox" className="form-check-input"
                    id={'genre-' + slug}
                    checked={form.favorite_genres.includes(slug)}
                    disabled={!form.favorite_genres.includes(slug) && form.favorite_genres.length >= 5}
                    onChange={(event) => change('favorite_genres', event.target.checked
                      ? [...form.favorite_genres, slug]
                      : form.favorite_genres.filter((genre) => genre !== slug))}
                  />
                  <label className="form-check-label" htmlFor={'genre-' + slug}>{label}</label>
                </div>
              </div>
            ))}
          </div>
          <FieldErrors field="favorite_genres" errors={errors} />
        </fieldset>

        <FieldErrors field="profile" errors={errors} />
        <div className="d-flex flex-wrap gap-2">
          <button type="submit" className="btn btn-primary">
            {saving ? 'Saving…' : 'Save changes'}
          </button>
          <button type="button" className="btn btn-outline-secondary" onClick={onCancel}>
            Cancel
          </button>
        </div>
      </fieldset>
      {saving && <p role="status" className="mt-3">Saving your profile…</p>}
    </form>
  );
}
