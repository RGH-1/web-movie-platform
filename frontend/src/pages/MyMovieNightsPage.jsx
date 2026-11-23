import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import MovieNightError from '../components/MovieNightError';
import MovieNightForm from '../components/MovieNightForm';
import MoviePagination from '../components/MoviePagination';
import { getMovieNights, getMyMovieNightInvitations, respondToInvitation } from '../services/movieNights';
import { formatMovieNightStart, meetingLink, movieNightStatus } from '../utils/movieNights';

export default function MyMovieNightsPage() {
    const navigate = useNavigate();
    const [creating, setCreating] = useState(false);
    const [request, setRequest] = useState({ page: 1 });
    const [result, setResult] = useState(null);
    const [eventsVersion, setEventsVersion] = useState(0);
    const [tab, setTab] = useState('events');
    const [invitationStatus, setInvitationStatus] = useState('pending');
    const [inbox, setInbox] = useState(null);
    const [rsvpId, setRsvpId] = useState(null);

    useEffect(() => {
        const controller = new AbortController();
        getMovieNights(request.page, { signal: controller.signal }).then((response) => {
            if (controller.signal.aborted) return;
            if (request.page > response.meta.last_page) {
                setRequest({ page: response.meta.last_page });
                return;
            }
            setResult({ request, response });
        }).catch((error) => {
            if (!controller.signal.aborted) setResult({ request, error });
        });
        return () => controller.abort();
    }, [request, eventsVersion]);

    function loadInbox() {
        getMyMovieNightInvitations().then(setInbox).catch((error) => setInbox({ error }));
    }

    useEffect(() => { loadInbox(); }, []);

    const current = result?.request === request ? result : null;
    const nights = current?.response?.data;
    const meta = current?.response?.meta;
    const invitations = inbox?.data ?? [];
    const pendingCount = invitations.filter((invitation) => invitation.status === 'pending').length;
    const visibleInvitations = invitations.filter((invitation) => invitation.status === invitationStatus);

    async function rsvp(invitation, status) {
        if (rsvpId !== null) return;
        setRsvpId(invitation.id);
        try {
            await respondToInvitation(invitation.movie_night_id, status);
            setInvitationStatus(status);
            loadInbox();
            setEventsVersion((value) => value + 1);
        } catch (error) {
            setInbox((value) => ({ ...value, actionError: error }));
        } finally {
            setRsvpId(null);
        }
    }

    return (
        <main className="container py-4 py-md-5 text-start">
            <Link to="/">Back to home</Link>
            <header className="d-flex flex-wrap justify-content-between align-items-center gap-3 my-3">
                <h1 className="h2 mb-0">My Movie Nights</h1>
                {tab === 'events' && nights && !creating && (
                    <button type="button" className="btn btn-primary" onClick={() => setCreating(true)}>Plan a movie night</button>
                )}
            </header>

            <div className="d-flex flex-wrap gap-2 mb-4" role="tablist" aria-label="Movie night sections">
                <button type="button" role="tab" aria-selected={tab === 'events'} className={`btn ${tab === 'events' ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setTab('events')}>My Events</button>
                <button type="button" role="tab" aria-selected={tab === 'invitations'} className={`btn ${tab === 'invitations' ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setTab('invitations')}>My Invitations ({pendingCount})</button>
            </div>

            {tab === 'events' && !current && <p role="status">Loading movie nights…</p>}
            {tab === 'events' && current?.error && <MovieNightError error={current.error} message="Could not load your movie nights."
                onRetry={() => setRequest({ page: request.page })} />}
            {tab === 'events' && creating && <MovieNightForm onCancel={() => setCreating(false)}
                onSaved={(night) => navigate(`/movie-nights/${night.id}`, { state: { movieNightCreated: true } })} />}
            {tab === 'events' && nights?.length === 0 && <p role="status">You don't have any movie nights yet.</p>}
            {tab === 'events' && nights?.length > 0 && (
                <div className="row g-3">
                    {nights.map((night) => (
                        <div className="col-12 col-md-6 col-xl-4" key={night.id}>
                            <article className="card h-100">
                                <div className="card-body">
                                    <h2 className="h5 text-break"><Link to={`/movie-nights/${night.id}`}>{night.title}</Link></h2>
                                    <p className="text-body-secondary">{movieNightStatus[night.status]} · {night.viewer_role === 'organizer' ? 'Organizing' : 'Participating'}</p>
                                    <p className="text-break mb-2">Organizer: {night.organizer.name}</p>
                                    <time dateTime={night.starts_at}>{formatMovieNightStart(night)} ({night.timezone})</time>
                                    {night.location && <p className="text-break mt-2 mb-0">{night.location}</p>}
                                </div>
                            </article>
                        </div>
                    ))}
                </div>
            )}
            {tab === 'events' && meta?.last_page > 1 && <MoviePagination page={meta.current_page} totalPages={meta.last_page}
                loading={creating} onPageChange={(page) => setRequest({ page })} label="Movie night pagination" />}

            {tab === 'invitations' && <section aria-labelledby="my-invitations-heading"><h2 id="my-invitations-heading" className="h4">My Invitations</h2>
                <div className="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Invitation history"><button type="button" className={`btn btn-sm ${invitationStatus === 'pending' ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setInvitationStatus('pending')}>Pending</button><button type="button" className={`btn btn-sm ${invitationStatus === 'accepted' ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setInvitationStatus('accepted')}>Accepted</button><button type="button" className={`btn btn-sm ${invitationStatus === 'declined' ? 'btn-primary' : 'btn-outline-primary'}`} onClick={() => setInvitationStatus('declined')}>Declined</button></div>
                {!inbox && <p role="status">Loading invitations…</p>}
                {inbox?.error && <MovieNightError error={inbox.error} message="Could not load your invitations." onRetry={loadInbox} />}
                {inbox?.actionError && <MovieNightError error={inbox.actionError} message="Could not update this invitation." onRetry={loadInbox} />}
                {inbox && !inbox.error && visibleInvitations.length === 0 && <p role="status">{invitations.length === 0 ? 'You do not have any movie night invitations yet.' : `No ${invitationStatus} invitations.`}</p>}
                <div className="row g-3">{visibleInvitations.map((invitation) => {
                    const preview = invitation.movie_night_preview; const meeting = meetingLink(preview.meeting_url); const readOnly = preview.status === 'cancelled';
                    return <div className="col-12 col-md-6" key={invitation.id}><article className="card h-100"><div className="card-body d-flex flex-column"><div className="d-flex flex-wrap justify-content-between gap-2"><h3 className="h5 text-break">{preview.title}</h3><span className={`badge text-bg-${invitation.status === 'pending' ? 'warning' : invitation.status === 'accepted' ? 'success' : 'secondary'}`}>{invitation.status}</span></div><p className="mb-1">Invited by {preview.organizer.name}</p><time className="text-break" dateTime={preview.starts_at}>{formatMovieNightStart(preview)} ({preview.timezone})</time>{preview.location && <p className="text-break mt-2 mb-0">Location: {preview.location}</p>}{preview.description && <p className="text-break mt-2 mb-0" style={{ whiteSpace: 'pre-wrap' }}>{preview.description}</p>}{meeting && <a className="text-break mt-2" href={meeting} target="_blank" rel="noreferrer">Open meeting link</a>}<p className="small text-body-secondary mt-2 mb-0">Event: {movieNightStatus[preview.status]}{readOnly && ' (read-only)'}</p><div className="d-flex flex-wrap gap-2 mt-3">{!readOnly && invitation.status !== 'accepted' && <button type="button" className="btn btn-success btn-sm" disabled={rsvpId !== null} onClick={() => rsvp(invitation, 'accepted')}>Accept</button>}{!readOnly && invitation.status !== 'declined' && <button type="button" className="btn btn-outline-danger btn-sm" disabled={rsvpId !== null} onClick={() => rsvp(invitation, 'declined')}>Decline</button>}{invitation.status === 'accepted' && !readOnly && <Link className="btn btn-outline-primary btn-sm" to={`/movie-nights/${invitation.movie_night_id}`}>View Movie Night</Link>}</div></div></article></div>;
                })}</div>
            </section>}
        </main>
    );
}
