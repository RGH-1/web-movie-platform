import { useEffect, useState } from 'react';
import { getUserRelationshipList } from '../services/profile';
import { getInvitations, inviteToMovieNight, respondToInvitation } from '../services/movieNights';
import { formatMovieNightStart, meetingLink, movieNightStatus } from '../utils/movieNights';

const statuses = ['pending', 'accepted', 'declined'];

export default function MovieNightInvitations({ nightId, organizer, status, onAccepted, invitation = null }) {
    const [data, setData] = useState(null); const [people, setPeople] = useState([]);
    const [inviteeId, setInviteeId] = useState(''); const [busy, setBusy] = useState(false); const [error, setError] = useState('');
    const [previewInvitation, setPreviewInvitation] = useState(invitation);
    const own = organizer?.viewer_role === 'organizer';
    const refresh = () => getInvitations(nightId).then(setData).catch((e) => setError(e));
    useEffect(() => { refresh(); }, [nightId]);
    useEffect(() => {
        if (!own || !organizer?.id) return;
        const controller = new AbortController();
        Promise.all(['following', 'followers'].map((kind) => getUserRelationshipList(String(organizer.id), kind, 1, { signal: controller.signal })))
            .then((rows) => { if (!controller.signal.aborted) setPeople([...new Map(rows.flatMap((row) => row.data).filter((p) => String(p.id) !== String(organizer.id)).map((p) => [p.id, p])).values()]); })
            .catch(() => {});
        return () => controller.abort();
    }, [own, organizer?.id]);
    async function invite(event) { event.preventDefault(); if (!inviteeId || busy) return; setBusy(true); setError(''); try { await inviteToMovieNight(nightId, inviteeId); setInviteeId(''); await refresh(); } catch (e) { setError(e); } finally { setBusy(false); } }
    async function rsvp(next) { if (busy) return; setBusy(true); setError(''); try { const updatedInvitation = await respondToInvitation(nightId, next); if (invitation) setPreviewInvitation(updatedInvitation); await refresh(); if (next === 'accepted') onAccepted?.(updatedInvitation); } catch (e) { setError(e); } finally { setBusy(false); } }
    const ownInvitation = statuses.flatMap((key) => data?.[key] ?? [])[0];
    const displayedInvitation = previewInvitation ?? ownInvitation;
    const preview = displayedInvitation?.movie_night_preview;
    const previewMeeting = meetingLink(preview?.meeting_url);
    const canAccept = displayedInvitation?.status !== 'accepted';
    const canDecline = displayedInvitation?.status !== 'declined';
    if (invitation && preview) return <section className="card shadow-sm" aria-labelledby="invitation-preview-heading"><div className="card-body"><p className="text-uppercase small text-body-secondary mb-1">Movie night invitation</p><h1 id="invitation-preview-heading" className="h3 text-break">{preview.title}</h1><p>Invited by <strong>{preview.organizer?.name}</strong></p><dl className="row mb-3"><dt className="col-sm-3">Date and time</dt><dd className="col-sm-9 text-break"><time dateTime={preview.starts_at}>{formatMovieNightStart(preview)} ({preview.timezone})</time></dd>{preview.location && <><dt className="col-sm-3">Location</dt><dd className="col-sm-9 text-break">{preview.location}</dd></>}{preview.description && <><dt className="col-sm-3">Description</dt><dd className="col-sm-9 text-break" style={{ whiteSpace: 'pre-wrap' }}>{preview.description}</dd></>}{previewMeeting && <><dt className="col-sm-3">Meeting</dt><dd className="col-sm-9 text-break"><a href={previewMeeting} target="_blank" rel="noreferrer">Open meeting link</a></dd></>}</dl><p className={`alert ${preview.status === 'cancelled' ? 'alert-warning' : 'alert-info'}`}>Event: {movieNightStatus[preview.status]} · Invitation: {displayedInvitation.status}</p>{preview.status !== 'cancelled' && <div className="d-flex flex-wrap gap-2">{canAccept && <button className="btn btn-success flex-grow-1 flex-sm-grow-0 text-nowrap" disabled={busy} onClick={() => rsvp('accepted')}>Accept invitation</button>}{canDecline && <button className="btn btn-outline-danger flex-grow-1 flex-sm-grow-0 text-nowrap" disabled={busy} onClick={() => rsvp('declined')}>Decline</button>}</div>}{error && <p className="alert alert-danger mt-3" role="alert">Could not update invitation.</p>}</div></section>;
    return <section className="card mb-3" aria-labelledby="invitations-heading"><div className="card-body"><h2 id="invitations-heading" className="h5">Invitations</h2>
        {error && <p className="alert alert-danger" role="alert">{error.response?.data?.message ?? 'Could not update invitations. Please try again.'}</p>}
        {own && status !== 'cancelled' && <form onSubmit={invite} className="mb-3"><label className="form-label" htmlFor="invitee">Invite a follower</label><div className="d-flex flex-column flex-sm-row gap-2"><select id="invitee" className="form-select" value={inviteeId} onChange={(e) => setInviteeId(e.target.value)}><option value="">Choose a follower or enter an ID below</option>{people.map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</select><button className="btn btn-primary text-nowrap" disabled={busy || !inviteeId}>Invite</button></div><label className="form-label mt-2" htmlFor="invitee-id">Or invite exact user ID</label><input id="invitee-id" className="form-control" value={inviteeId} onChange={(e) => setInviteeId(e.target.value.replace(/\D/g, ''))} inputMode="numeric" /></form>}
        {!own && ownInvitation && <div className="alert alert-info text-break">Your invitation is <strong>{ownInvitation.status}</strong>. {status !== 'cancelled' && <div className="d-flex flex-wrap gap-2 mt-2">{ownInvitation.status !== 'accepted' && <button className="btn btn-sm btn-success flex-grow-1 flex-sm-grow-0 text-nowrap" disabled={busy} onClick={() => rsvp('accepted')}>Accept</button>}{ownInvitation.status !== 'declined' && <button className="btn btn-sm btn-outline-danger flex-grow-1 flex-sm-grow-0 text-nowrap" disabled={busy} onClick={() => rsvp('declined')}>Decline</button>}</div>}</div>}
        {own && statuses.map((key) => <div key={key}><h3 className="h6 text-capitalize">{key}</h3>{data ? (data[key]?.length ? <ul className="list-group mb-2">{data[key].map((item) => <li className="list-group-item" key={item.id}>{item.invitee.name}</li>)}</ul> : <p className="small text-body-secondary">None.</p>) : <p role="status">Loading invitations…</p>}</div>)}
        <button type="button" className="btn btn-link p-0" onClick={() => navigator.clipboard?.writeText(`${window.location.origin}/movie-nights/${nightId}`)}>Copy invitation link</button>
    </div></section>;
}
