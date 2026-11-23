import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import MovieNightError from '../components/MovieNightError';
import MovieNightForm from '../components/MovieNightForm';
import { cancelMovieNight, getInvitations, getMovieNight } from '../services/movieNights';
import { formatMovieNightStart, meetingLink, movieNightStatus } from '../utils/movieNights';
import { MovieNightCalendarAction } from '../components/CalendarActions';
import MovieNightInvitations from '../components/MovieNightInvitations';
import MovieNightShortlist from '../components/MovieNightShortlist';


export default function MovieNightDetailsPage() {
    const { nightId } = useParams();
    return <MovieNightDetails key={nightId} nightId={nightId} />;
}

function MovieNightDetails({ nightId }) {
    const { state } = useLocation();
    const [night, setNight] = useState(null);
    const [attempt, setAttempt] = useState(0);
    const [readError, setReadError] = useState(null);
    const [invitation, setInvitation] = useState(null);
    const [cancelError, setCancelError] = useState(null);
    const [mode, setMode] = useState(null);
    const [cancelling, setCancelling] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [tool, setTool] = useState(null);
    const [notice, setNotice] = useState(state?.movieNightCreated ? 'Movie night created.' : '');
    const mutation = useRef(null);

    useEffect(() => {
        const controller = new AbortController();
        getMovieNight(nightId, { signal: controller.signal }).then((response) => {
            if (!controller.signal.aborted) setNight(response);
        }).catch((error) => {
            if (controller.signal.aborted || error.response?.status !== 404) {
                if (!controller.signal.aborted) setReadError(error);
                return;
            }
            getInvitations(nightId, { signal: controller.signal }).then((grouped) => {
                const own = ['pending', 'accepted', 'declined'].flatMap((key) => grouped[key] ?? [])[0];
                if (!controller.signal.aborted && own) setInvitation(own);
                else if (!controller.signal.aborted) setReadError(error);
            }).catch(() => { if (!controller.signal.aborted) setReadError(error); });
        });
        return () => controller.abort();
    }, [nightId, attempt]);

    useEffect(() => () => mutation.current?.abort(), []);

    function handleCalendarAccessError(error) {
        setNight(null);
        setMode(null);
        setCancelError(null);
        setNotice('');
        setReadError(error);
    }

    async function confirmCancellation() {
        if (mutation.current || exporting || !night.permissions.cancel) return;
        const controller = new AbortController();
        mutation.current = controller;
        setCancelling(true);
        setCancelError(null);
        try {
            const response = await cancelMovieNight(night.id, { signal: controller.signal });
            if (controller.signal.aborted) return;
            setNight(response);
            setMode(null);
            setNotice('Movie night cancelled.');
        } catch (error) {
            if (!controller.signal.aborted) setCancelError(error);
        } finally {
            if (!controller.signal.aborted) {
                mutation.current = null;
                setCancelling(false);
            }
        }
    }

    const meeting = meetingLink(night?.meeting_url);
    const winner = night?.winning_movie;
    return (
        <main className="container py-4 py-md-5 text-start">
            <Link to="/movie-nights">Back to My Movie Nights</Link>
            <header className="d-flex flex-wrap justify-content-between align-items-center gap-3 my-3">
                <h1 className="h2 text-break mb-0">{night?.title ?? 'Movie night details'}</h1>
                {night && !mode && (
                    <div className="d-flex flex-wrap gap-2">
                        {night.permissions.update && (
                            <button
                                type="button"
                                className="btn btn-outline-primary"
                                disabled={exporting}
                                onClick={() => {
                                    if (exporting) return;
                                    setNotice('');
                                    setMode('edit');
                                }}
                            >
                                Edit movie night
                            </button>
                        )}

                        {night.permissions.cancel && (
                            <button
                                type="button"
                                className="btn btn-outline-danger"
                                disabled={exporting}
                                onClick={() => {
                                    if (exporting) return;
                                    setNotice('');
                                    setMode('cancel');
                                }}
                            >
                                Cancel movie night
                            </button>
                        )}
                    </div>
                )}
            </header>

            {!night && !invitation && !readError && <p role="status">Loading movie night…</p>}
            {readError && <MovieNightError error={readError} message="Could not load this movie night."
                onRetry={() => { setReadError(null); setAttempt(attempt + 1); }} />}
            {notice && <p role="status" className="alert alert-success">{notice}</p>}
            {!night && invitation && <MovieNightInvitations nightId={nightId} invitation={invitation}
                onAccepted={() => { setInvitation(null); setReadError(null); setAttempt((value) => value + 1); }} />}
            {night && (
                <>
                    {mode === 'edit' && <MovieNightForm night={night} onCancel={() => setMode(null)}
                        onSaved={(updated) => {
                            setNight(updated);
                            setMode(null);
                            setNotice('Movie night saved.');
                        }} />}
                    {mode === 'cancel' && (
                        <section className="alert alert-warning" aria-labelledby="confirm-cancel-title">
                            <h2 id="confirm-cancel-title" className="h5">Cancel this movie night?</h2>
                            <p>The event will be marked cancelled and will remain in My Movie Nights.</p>
                            {cancelError && <MovieNightError error={cancelError} message="Could not cancel this movie night. Please try again." />}
                            <div className="d-flex flex-wrap gap-2">
                                <button type="button" className="btn btn-danger" onClick={confirmCancellation}
                                    disabled={cancelling}>{cancelling ? 'Cancelling…' : 'Confirm cancellation'}</button>
                                <button type="button" className="btn btn-outline-secondary" disabled={cancelling}
                                    onClick={() => { setCancelError(null); setMode(null); }}>Keep movie night</button>
                            </div>
                        </section>
                    )}

                    <div className="row g-4">
                        <section className="col-12 col-lg-8" aria-labelledby="night-overview">
                            <h2 id="night-overview" className="h4">Event details</h2>
                            {night.description && <p className="text-break" style={{ whiteSpace: 'pre-wrap' }}>{night.description}</p>}
                            <dl className="row mb-0">
                                <dt className="col-sm-4">Organizer</dt>
                                <dd className="col-sm-8 text-break"><Link to={`/users/${night.organizer.id}`}>{night.organizer.name}</Link></dd>
                                <dt className="col-sm-4">Status</dt>
                                <dd className="col-sm-8">{movieNightStatus[night.status]}</dd>
                                <dt className="col-sm-4">Scheduled time</dt>
                                <dd className="col-sm-8"><time dateTime={night.starts_at}>{formatMovieNightStart(night)} ({night.timezone})</time></dd>
                                <dt className="col-sm-4">Participants</dt>
                                <dd className="col-sm-8">{night.summaries.participants.count} confirmed, excluding the organizer</dd>
                                {night.location && <><dt className="col-sm-4">Location</dt><dd className="col-sm-8 text-break">{night.location}</dd></>}
                                {meeting && <><dt className="col-sm-4">Meeting link</dt><dd className="col-sm-8">
                                    <a href={meeting} target="_blank" rel="noreferrer">Open meeting link</a>
                                </dd></>}
                                {winner && <><dt className="col-sm-4">Winning movie</dt><dd className="col-sm-8 text-break">
                                    {winner.is_active ? <Link to={`/movies/local/${winner.local_movie_id}`}>{winner.title}</Link> : winner.title}
                                </dd></>}
                            </dl>
                        </section>
                        <aside className="col-12 col-lg-4" aria-labelledby="night-tools">
                            <h2 id="night-tools" className="h4">Movie night tools</h2>

                            <section
                                className="card mb-3"
                                aria-labelledby="night-calendar-title"
                            >
                                <div className="card-body">
                                    <h3 id="night-calendar-title" className="h5">
                                        Calendar export
                                    </h3>

                                    <MovieNightCalendarAction
                                        night={night}
                                        disabled={mode !== null || cancelling}
                                        onBusyChange={setExporting}
                                        onRefresh={(updated) => {
                                            setNight(updated);
                                            setNotice('');
                                        }}
                                        onAccessError={handleCalendarAccessError}
                                    />

                                    <p className="small text-body-secondary mb-0">
                                        Calendar downloads are snapshots. Export again after
                                        schedule changes, and remove cancelled events from
                                        your calendar.
                                    </p>
                                </div>
                            </section>
                            <div className="d-flex flex-wrap gap-2 mb-3"><button type="button" className="btn btn-outline-primary" onClick={() => setTool(tool === 'invitations' ? null : 'invitations')}>Invitations</button><button type="button" className="btn btn-outline-primary" onClick={() => setTool(tool === 'shortlist' ? null : 'shortlist')}>Shortlist and voting</button></div>
                            {tool === 'invitations' && <MovieNightInvitations nightId={night.id} organizer={{ ...night.organizer, viewer_role: night.viewer_role }}
                                status={night.status} onAccepted={() => setAttempt((value) => value + 1)} />}
                            {tool === 'shortlist' && <MovieNightShortlist night={night} onFinalized={() => setAttempt((value) => value + 1)} />}
                            <div className="list-group"><div className="list-group-item d-flex flex-wrap justify-content-between gap-2">Watch room <Link className="btn btn-sm btn-outline-primary" to={`/movie-nights/${night.id}/watch-room`}>Open watch room</Link></div></div>
                        </aside>
                    </div>
                </>
            )}
        </main>
    );
}
