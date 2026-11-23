import { useEffect, useRef, useState } from 'react';
import api from '../services/api';
import { getMovieNight } from '../services/movieNights';
import {
    buildMovieNightIcs,
    buildReleaseIcs,
    isUpcomingRelease,
    releaseKey,
} from '../utils/calendar.js';
import { downloadCalendar } from '../utils/downloadCalendar.js';

function CalendarDownloadButton({
    load,
    filename,
    label,
    disabled = false,
    onFailure,
    onBusyChange,
}) {
    const pending = useRef(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    useEffect(() => () => {
        pending.current?.abort();
        onBusyChange?.(false);
    }, [onBusyChange]);

    async function download() {
        if (disabled || pending.current) return;

        const controller = new AbortController();
        pending.current = controller;
        setSaving(true);
        setError('');
        setSuccess('');
        onBusyChange?.(true);

        try {
            const content = await load({ signal: controller.signal });

            if (!controller.signal.aborted) {
                downloadCalendar(content, filename);
                setSuccess(
                    'Calendar file prepared. Open the downloaded .ics file to import it.',
                );
            }
        } catch (failure) {
            if (controller.signal.aborted) return;

            const status = failure.response?.status;

            setError(
                status === 401
                    ? 'Sign in again before exporting this event.'
                    : status === 403
                        ? 'Your profile or permissions do not allow this export.'
                        : status === 404
                            ? 'This movie night is no longer available to you.'
                            : 'Could not prepare the calendar file. Refresh the event and try again.',
            );

            onFailure?.(failure);
        } finally {
            if (!controller.signal.aborted) {
                pending.current = null;
                setSaving(false);
                onBusyChange?.(false);
            }
        }
    }

    return (
        <div className="my-3">
            <button
                type="button"
                className="btn btn-outline-primary"
                onClick={download}
                disabled={disabled || saving}
                aria-busy={saving}
            >
                {saving ? 'Preparing calendar...' : label}
            </button>

            {error && (
                <p className="alert alert-danger mt-3" role="alert">
                    {error}
                </p>
            )}

            {success && (
                <p className="text-success mt-2" role="status">
                    {success}
                </p>
            )}
        </div>
    );
}

export function ReleaseCalendarAction({ movie }) {
    if (!isUpcomingRelease(movie)) return null;

    return (
        <section className="mb-4" aria-label="Release calendar">
            <h2 className="h5">Release reminder</h2>

            <p className="text-body-secondary">
                Add an all-day reminder for the listed release date: {movie.release_date}.
            </p>

            <CalendarDownloadButton
                label="Add release to calendar"
                filename={`release-${releaseKey(movie)}.ics`}
                load={() => buildReleaseIcs(movie, {
                    sourceUrl: api.defaults.baseURL,
                })}
            />
        </section>
    );
}

export function MovieNightCalendarAction({
    night,
    disabled = false,
    onRefresh,
    onAccessError,
    onBusyChange,
}) {
    if (night.status === 'draft') {
        return <p>Schedule this movie night before adding it to a calendar.</p>;
    }

    if (night.status === 'cancelled') {
        return <p>Cancelled movie nights cannot be added to a calendar.</p>;
    }

    if (!['scheduled', 'finalized'].includes(night.status)) {
        return <p>Calendar export is unavailable for this movie night.</p>;
    }

    async function load({ signal }) {
        const latest = await getMovieNight(night.id, { signal });

        if (signal.aborted) return null;

        if (latest?.id !== night.id) {
            throw new Error('Unexpected movie-night response.');
        }

        if (!['scheduled', 'finalized'].includes(latest.status)) {
            onRefresh?.(latest);
            throw new Error('Movie night is no longer scheduled.');
        }

        const content = buildMovieNightIcs(latest, {
            sourceUrl: api.defaults.baseURL,
        });

        if (!signal.aborted) onRefresh?.(latest);

        return content;
    }

    return (
        <>
            <CalendarDownloadButton
                label="Add movie night to calendar"
                filename={`movie-night-${night.id}.ics`}
                load={load}
                disabled={disabled}
                onBusyChange={onBusyChange}
                onFailure={(failure) => {
                    if ([401, 403, 404].includes(failure.response?.status)) {
                        onAccessError?.(failure);
                    }
                }}
            />

            <p className="small text-body-secondary">
                This is a start-time reminder; no end time is set.
            </p>
        </>
    );
}