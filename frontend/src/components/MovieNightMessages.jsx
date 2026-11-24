import { useEffect, useRef, useState } from 'react';
import { subscribeToMovieNight } from '../services/movieNightBroadcast';
import { getMovieNightMessages, sendMovieNightMessage } from '../services/movieNights';

const connectionLabels = {
    connecting: 'Connecting to live chat…',
    live: 'Live chat connected.',
    unavailable: 'Live updates are unavailable. You can reload messages manually.',
};

const messageTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function compareIds(left, right) {
    return left.length - right.length || left.localeCompare(right);
}

function mergeMessages(current, incoming) {
    const byId = new Map(current.map((message) => [message.id, message]));
    for (const message of incoming) byId.set(message.id, message);
    return [...byId.values()].sort((a, b) => compareIds(a.id, b.id));
}

export default function MovieNightMessages({ nightId, canSend }) {
    const [messages, setMessages] = useState([]);
    const [loading, setLoading] = useState(true);
    const [text, setText] = useState('');
    const [sending, setSending] = useState(false);
    const [readError, setReadError] = useState('');
    const [sendError, setSendError] = useState('');
    const [connection, setConnection] = useState('connecting');
    const room = useRef(null);

    useEffect(() => {
        const controller = new AbortController();
        const { signal } = controller;
        let latestId;
        let pending = false;
        let running = false;

        async function refresh() {
            pending = true;
            if (running || signal.aborted) return;
            running = true;

            while (pending && !signal.aborted) {
                pending = false;
                try {
                    let cursor;
                    let newestId;
                    const incoming = [];
                    do {
                        const page = await getMovieNightMessages(nightId, { cursor, signal });
                        newestId ??= page.data[0]?.id;
                        incoming.push(...page.data.filter((message) =>
                            latestId === undefined || compareIds(message.id, latestId) > 0));
                        cursor = page.meta.next_cursor;
                        if (incoming.length === 0 || (latestId !== undefined &&
                            page.data.some((message) => compareIds(message.id, latestId) <= 0))) break;
                    } while (latestId !== undefined && cursor);

                    if (signal.aborted) break;
                    // An initially empty room still needs to catch up across multiple pages.
                    latestId = newestId ?? latestId ?? '0';
                    setMessages((current) => mergeMessages(current, incoming));
                    setReadError('');
                } catch (error) {
                    if (signal.aborted) break;
                    if ([401, 403, 404].includes(error.response?.status)) {
                        latestId = undefined;
                        setMessages([]);
                    }
                    setReadError('Could not load room messages.');
                } finally {
                    if (!signal.aborted) setLoading(false);
                }
            }

            running = false;
        }

        room.current = { refresh, signal };
        const leave = subscribeToMovieNight(nightId, {
            refresh,
            onStatus: (status) => {
                if (!signal.aborted) setConnection(status);
            },
        }, signal);
        void refresh();

        return () => {
            controller.abort();
            leave();
        };
    }, [nightId]);

    async function submit(event) {
        event.preventDefault();
        const { signal, refresh } = room.current;
        if (!text.trim() || sending || signal.aborted) return;

        setSending(true);
        setSendError('');
        try {
            const message = await sendMovieNightMessage(nightId, text.trim(), { signal });
            if (signal.aborted) return;
            setMessages((current) => mergeMessages(current, [message]));
            setText('');
            void refresh();
        } catch (error) {
            if (!signal.aborted) {
                setSendError(error.response?.status === 429
                    ? 'Too many messages. Wait a minute and try again.'
                    : 'Could not send the message. Please try again.');
            }
        } finally {
            if (!signal.aborted) setSending(false);
        }
    }

    return (
        <section className="card mt-3" aria-labelledby="room-messages-heading">
            <div className="card-body">
                <h2 id="room-messages-heading" className="h5">Room messages</h2>
                {loading && <p role="status">Loading messages…</p>}
                <p className="small text-body-secondary" role="status">{connectionLabels[connection]}</p>
                {readError && <p role="alert">{readError}</p>}
                {(connection === 'unavailable' || readError) && (
                    <button type="button" className="btn btn-outline-secondary btn-sm mb-2"
                        onClick={() => void room.current.refresh()}>Reload messages</button>
                )}
                {!loading && !readError && messages.length === 0 && <p>No messages yet.</p>}
                <ol className="list-group mb-3" aria-live="polite">
                    {messages.map((message) => (
                        <li key={message.id} className="list-group-item text-break">
                            <div className="d-flex flex-wrap justify-content-between gap-2 mb-1">
                                <strong>{message.sender_name || 'Unknown sender'}</strong>
                                <time className="small text-body-secondary" dateTime={message.created_at}>
                                    {messageTime.format(new Date(message.created_at))}
                                </time>
                            </div>
                            {message.is_spoiler
                                ? <details><summary>Spoiler</summary>{message.text}</details>
                                : <p className="mb-0" style={{ whiteSpace: 'pre-wrap' }}>{message.text}</p>}
                        </li>
                    ))}
                </ol>
                {canSend && (
                    <form onSubmit={submit}>
                        <label htmlFor="room-message" className="form-label">Message</label>
                        <textarea id="room-message" className="form-control mb-2"
                            value={text} onChange={(event) => setText(event.target.value)}
                            maxLength={1000} disabled={sending} required />
                        {sendError && <p role="alert">{sendError}</p>}
                        <button className="btn btn-primary" disabled={sending || !text.trim()}>
                            {sending ? 'Sending…' : 'Send'}
                        </button>
                    </form>
                )}
            </div>
        </section>
    );
}
