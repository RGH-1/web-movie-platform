import { useEffect, useRef, useState } from 'react';
import { getAdminMovie, saveAdminMovie } from '../services/adminMovies';
import { movieChanges, movieCollections, movieFields, movieFormValues } from '../utils/adminMovieForm';
import AdminMovieError from './AdminMovieError';

export default function AdminMovieForm({ movieId, onSaved, onClose }) {
    const [attempt, setAttempt] = useState(0);
    const [result, setResult] = useState(null);

    useEffect(() => {
        if (!movieId) return;
        const controller = new AbortController();
        getAdminMovie(movieId, { signal: controller.signal }).then((movie) => {
            if (!controller.signal.aborted) setResult({ attempt, movie });
        }).catch((error) => {
            if (!controller.signal.aborted) setResult({ attempt, error });
        });
        return () => controller.abort();
    }, [movieId, attempt]);

    const current = result?.attempt === attempt ? result : null;
    if (movieId && !current?.movie) {
        return (
            <section aria-label="Edit movie">
                {!current && <p role="status">Loading movie details…</p>}
                {current?.error && <AdminMovieError error={current.error} message="Could not load this movie."
                    onRetry={() => setAttempt((value) => value + 1)} />}
                <button type="button" className="btn btn-outline-secondary" onClick={onClose}>Back to catalog</button>
            </section>
        );
    }

    return <MovieFields movie={current?.movie} onSaved={onSaved} onClose={onClose} />;
}

function FormField({ field, fieldKey = field.name, value, error, onChange }) {
    const { label, required, type, rows } = field;
    const id = `admin-movie-${fieldKey}`;
    const Control = rows ? 'textarea' : 'input';
    return (
        <div>
            <label htmlFor={id} className="form-label">{label}</label>
            <Control id={id} className="form-control" rows={rows} type={type}
                min={type === 'number' ? 1 : undefined} step={type === 'number' ? 1 : undefined}
                required={required} value={value}
                aria-invalid={!!error} aria-describedby={error ? `${id}-error` : undefined}
                onChange={(event) => onChange(event.target.value)} />
            {error && <p id={`${id}-error`} className="text-danger small mt-1 mb-0" role="alert">{error}</p>}
        </div>
    );
}

function MovieFields({ movie, onSaved, onClose }) {
    const [initial] = useState(() => movieFormValues(movie));
    const [values, setValues] = useState(initial);
    const [fieldErrors, setFieldErrors] = useState({});
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const mutation = useRef(null);

    useEffect(() => () => mutation.current?.abort(), []);

    async function submit(event) {
        event.preventDefault();
        if (mutation.current) return;
        setError(null);
        const { payload, errors } = movieChanges(values, initial, !movie);
        if (Object.keys(errors).length) {
            setFieldErrors(errors);
            return;
        }
        if (Object.keys(payload).length === 0) {
            setFieldErrors({ form: 'Change at least one field before saving.' });
            return;
        }

        const controller = new AbortController();
        mutation.current = controller;
        setBusy(true);
        setFieldErrors({});
        try {
            await saveAdminMovie(movie?.local_movie_id, payload, { signal: controller.signal });
            if (!controller.signal.aborted) onSaved(movie ? 'Movie updated.' : 'Movie created.');
        } catch (requestError) {
            if (controller.signal.aborted) return;
            if (requestError.response?.status === 422) {
                setFieldErrors(Object.fromEntries(
                    Object.entries(requestError.response.data?.errors ?? { form: ['Check the movie details.'] })
                        .map(([key, messages]) => [key, messages.join(' ')]),
                ));
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
        <section className="card" aria-labelledby="admin-movie-form-title">
            <div className="card-body">
                <h2 className="h4 text-break" id="admin-movie-form-title">{movie ? `Edit ${movie.title}` : 'Create local movie'}</h2>
                {movie?.source === 'tmdb' && (
                    <p className="text-body-secondary">
                        TMDB ID {movie.tmdb_id}. Changes to catalog fields become overrides.
                    </p>
                )}
                {error && <AdminMovieError error={error} message={movie
                    ? 'Could not save your changes. Please try again.'
                    : 'Could not confirm creation. Check the movie catalog before trying again.'} />}
                {fieldErrors.form && <p role="alert" className="text-danger">{fieldErrors.form}</p>}
                <form onSubmit={submit} noValidate>
                    <fieldset disabled={busy}>
                        <div className="row g-3">
                            {movieFields.map((field) => (
                                <div key={field.name} className={field.rows ? 'col-12' : 'col-12 col-md-6'}>
                                    <FormField field={field} value={values[field.name]}
                                        error={fieldErrors[field.name] || (field.name === 'trailer' ? fieldErrors['trailer.key'] || fieldErrors['trailer.site'] : null)}
                                        onChange={(value) => setValues({ ...values, [field.name]: value })} />
                                </div>
                            ))}
                        </div>
                        {movieCollections.map(({ name, title, item, fields }) => (
                            <fieldset key={name} className="mt-4">
                                <legend className="h5">{title}</legend>
                                {fieldErrors[name] && <p role="alert" className="text-danger">{fieldErrors[name]}</p>}
                                {values[name].map((row, index) => (
                                    <fieldset className="border rounded p-3 mb-3" key={index}>
                                        <legend className="h6 float-none w-auto px-1">{title === 'Cast' ? 'Cast member' : 'Genre'} {index + 1}</legend>
                                        <div className="row g-3 mb-2">
                                            {fields.map((field) => {
                                                const key = `${name}.${index}.${field.name}`;
                                                return <div key={field.name} className="col-12 col-md-6">
                                                    <FormField field={field} fieldKey={key} value={row[field.name]} error={fieldErrors[key]}
                                                        onChange={(value) => setValues({ ...values, [name]: values[name].map((entry, rowIndex) =>
                                                            rowIndex === index ? { ...entry, [field.name]: value } : entry) })} />
                                                </div>;
                                            })}
                                        </div>
                                        {fieldErrors[`${name}.${index}`] && <p role="alert" className="text-danger">{fieldErrors[`${name}.${index}`]}</p>}
                                        <button type="button" className="btn btn-sm btn-outline-danger" aria-label={`Remove ${item} ${index + 1}`}
                                            onClick={() => setValues({ ...values, [name]: values[name].filter((_, rowIndex) => rowIndex !== index) })}>
                                            Remove {item}
                                        </button>
                                    </fieldset>
                                ))}
                                <button type="button" className="btn btn-sm btn-outline-secondary"
                                    onClick={() => setValues({ ...values, [name]: [
                                        ...values[name], Object.fromEntries(fields.map((field) => [field.name, ''])),
                                    ] })}>Add {item}</button>
                            </fieldset>
                        ))}
                        <div className="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" className="btn btn-primary">{busy ? 'Saving…' : 'Save movie'}</button>
                            <button type="button" className="btn btn-outline-secondary" onClick={onClose}>Back to catalog</button>
                        </div>
                    </fieldset>
                </form>
            </div>
        </section>
    );
}
