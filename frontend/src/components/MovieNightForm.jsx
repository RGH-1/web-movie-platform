import { useEffect, useId, useRef, useState } from 'react';
import { saveMovieNight } from '../services/movieNights';
import { localDateTimeInput, meetingLink } from '../utils/movieNights';
import MovieNightError from './MovieNightError';

const fields = [
    { name: 'title', label: 'Title', required: true, maxLength: 255 },
    { name: 'starts_at', label: 'Date and time', type: 'datetime-local', required: true },
    { name: 'description', label: 'Description (optional)', rows: 3, maxLength: 2000 },
    { name: 'location', label: 'Location (optional)', maxLength: 255 },
    { name: 'meeting_url', label: 'Meeting link (optional)', type: 'url', maxLength: 2048 },
];

export default function MovieNightForm({ night, onSaved, onCancel }) {
    const prefix = useId();
    const mutation = useRef(null);
    const deviceTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const [initial] = useState(() => ({
        title: night?.title ?? '',
        starts_at: night ? localDateTimeInput(new Date(night.starts_at)) : '',
        description: night?.description ?? '',
        location: night?.location ?? '',
        meeting_url: night?.meeting_url ?? '',
        status: night?.status ?? 'draft',
    }));
    const [form, setForm] = useState(initial);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [errors, setErrors] = useState({});

    useEffect(() => () => mutation.current?.abort(), []);

    async function submit(event) {
        event.preventDefault();
        if (mutation.current) return;
        setError(null);

        const title = form.title.trim();
        const meeting = form.meeting_url.trim();
        const localStart = new Date(form.starts_at);
        const startChanged = !night || form.starts_at !== initial.starts_at;
        const scheduling = initial.status === 'draft' && form.status === 'scheduled';
        const start = startChanged ? localStart : new Date(night.starts_at);
        const nextErrors = {};
        if (!title) nextErrors.title = ['Enter a title.'];
        if (Number.isNaN(start.getTime()) || (startChanged && localDateTimeInput(start) !== form.starts_at)) {
            nextErrors.starts_at = ['Enter a valid date and time in your device time zone.'];
        } else if ((startChanged || scheduling) && start.getTime() <= Date.now()) {
            nextErrors.starts_at = ['Choose a date and time in the future.'];
        }
        if (meeting && !meetingLink(meeting)) nextErrors.meeting_url = ['Enter a web link beginning with https:// or http://.'];

        if (Object.keys(nextErrors).length) {
            setErrors(nextErrors);
            return;
        }

        const values = {
            title,
            description: form.description.trim() || null,
            location: form.location.trim() || null,
            meeting_url: meeting || null,
            status: form.status,
        };
        const payload = night ? Object.fromEntries(
            Object.entries(values).filter(([name, value]) => value !== night[name]),
        ) : { ...values, timezone: deviceTimezone };
        if (startChanged) payload.starts_at = start.toISOString();
        if (Object.keys(payload).length === 0) {
            setErrors({ movie_night: ['Change a field before saving.'] });
            return;
        }

        const controller = new AbortController();
        mutation.current = controller;
        setBusy(true);
        setErrors({});
        try {
            const saved = await saveMovieNight(night?.id, payload, { signal: controller.signal });
            if (!controller.signal.aborted) onSaved(saved);
        } catch (requestError) {
            if (controller.signal.aborted) return;
            if (requestError.response?.status === 422) {
                setErrors(requestError.response.data.errors ?? { movie_night: ['Check the information and try again.'] });
            } else {
                setError(requestError);
            }
        } finally {
            if (!controller.signal.aborted) {
                mutation.current = null;
                setBusy(false);
            }
        }
    }

    return (
        <section className="card mb-4" aria-labelledby={`${prefix}-title`}>
            <div className="card-body">
                <h2 className="h4" id={`${prefix}-title`}>{night ? 'Edit movie night' : 'Plan a movie night'}</h2>
                <p className="small text-body-secondary">Enter the date and time in your device time zone ({deviceTimezone}).</p>
                {error && <MovieNightError error={error} message={night
                    ? 'Could not save the movie night. Please try again.'
                    : 'Could not confirm creation. Check My Movie Nights before trying again.'} />}
                {errors.movie_night && <p role="alert" className="text-danger">{errors.movie_night.join(' ')}</p>}
                <form noValidate onSubmit={submit}>
                    <fieldset disabled={busy}>
                        {fields.map(({ name, label, rows, ...attributes }) => {
                            const id = `${prefix}-field-${name}`;
                            const Control = rows ? 'textarea' : 'input';
                            return (
                                <div key={name} className="mb-3">
                                    <label htmlFor={id} className="form-label">{label}</label>
                                    <Control {...attributes} rows={rows} id={id} className="form-control"
                                        value={form[name]} aria-invalid={!!errors[name]}
                                        aria-describedby={errors[name] ? `${id}-error` : undefined}
                                        onChange={(event) => setForm({ ...form, [name]: event.target.value })} />
                                    {errors[name] && <p id={`${id}-error`} className="small text-danger mt-1" role="alert">{errors[name].join(' ')}</p>}
                                </div>
                            );
                        })}
                        {initial.status === 'draft' && (
                            <div className="mb-3">
                                <label htmlFor={`${prefix}-status`} className="form-label">Status</label>
                                <select id={`${prefix}-status`} className="form-select" value={form.status}
                                    aria-invalid={!!errors.status}
                                    aria-describedby={errors.status ? `${prefix}-status-error` : undefined}
                                    onChange={(event) => setForm({ ...form, status: event.target.value })}>
                                    <option value="draft">Draft (only you can view it)</option>
                                    <option value="scheduled">Scheduled</option>
                                </select>
                            </div>
                        )}
                        {errors.status && <p id={`${prefix}-status-error`} className="small text-danger" role="alert">{errors.status.join(' ')}</p>}
                        {errors.timezone && <p className="small text-danger" role="alert">{errors.timezone.join(' ')}</p>}
                        <div className="d-flex flex-wrap gap-2">
                            <button type="submit" className="btn btn-primary">{busy ? 'Saving…' : 'Save movie night'}</button>
                            <button type="button" className="btn btn-outline-secondary" onClick={onCancel}>Close form</button>
                        </div>
                    </fieldset>
                </form>
            </div>
        </section>
    );
}
