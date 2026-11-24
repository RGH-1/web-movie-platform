import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import MovieNightMessages from '../../src/components/MovieNightMessages';
import { subscribeToMovieNight } from '../../src/services/movieNightBroadcast';
import { getMovieNightMessages, sendMovieNightMessage } from '../../src/services/movieNights';

vi.mock('../../src/services/movieNightBroadcast');
vi.mock('../../src/services/movieNights');

const createdAt = '2026-11-24T11:30:00.000000Z';
const page = (data, next_cursor = null) => ({ data, meta: { next_cursor } });

it('reloads across cursors after reconnect and renders each message once', async () => {
    const first = { id: '9', text: 'Before disconnect', sender_name: 'Organizer', created_at: createdAt };
    const missed = { id: '10', text: 'While offline', sender_name: 'Participant', created_at: createdAt };
    const sent = { id: '11', text: 'From this browser', sender_name: 'Organizer', created_at: createdAt };
    let notify;
    const leave = vi.fn();
    vi.mocked(subscribeToMovieNight).mockImplementation((_id, { refresh }) => {
        notify = refresh;
        return leave;
    });
    vi.mocked(getMovieNightMessages)
        .mockResolvedValueOnce(page([first]))
        .mockResolvedValueOnce(page([missed], 'older'))
        .mockResolvedValueOnce(page([first]))
        .mockResolvedValue(page([sent, missed, first]));
    vi.mocked(sendMovieNightMessage).mockResolvedValue(sent);

    const { unmount } = render(<MovieNightMessages nightId="1" canSend />);
    await screen.findByText(first.text);

    await act(async () => notify());
    expect(getMovieNightMessages).toHaveBeenCalledWith('1', expect.objectContaining({ cursor: 'older' }));
    expect(screen.getAllByText(first.text)).toHaveLength(1);
    expect(screen.getAllByText(missed.text)).toHaveLength(1);

    const user = userEvent.setup();
    await user.type(screen.getByRole('textbox', { name: 'Message' }), sent.text);
    await user.click(screen.getByRole('button', { name: 'Send' }));
    await act(async () => notify());
    const rows = screen.getAllByRole('listitem');
    expect(rows.map((row) => row.querySelector('p').textContent))
        .toEqual([first.text, missed.text, sent.text]);
    expect(within(rows[1]).getByText('Participant')).toBeInTheDocument();
    expect(rows[1].querySelector('time')).toHaveAttribute('datetime', createdAt);
    expect(rows[1].querySelector('time')).not.toBeEmptyDOMElement();

    unmount();
    expect(leave).toHaveBeenCalledOnce();
});

it('shows connection changes and preserves the message draft after a send failure', async () => {
    let setLiveStatus;
    vi.mocked(subscribeToMovieNight).mockImplementation((_id, { onStatus }) => {
        setLiveStatus = onStatus;
        return vi.fn();
    });
    vi.mocked(getMovieNightMessages).mockResolvedValue(page([]));
    vi.mocked(sendMovieNightMessage).mockRejectedValueOnce(new Error('Offline'));

    render(<MovieNightMessages nightId="2" canSend />);
    expect(screen.getByText('Connecting to live chat…')).toBeInTheDocument();
    await screen.findByText('No messages yet.');

    act(() => setLiveStatus('live'));
    expect(screen.getByText('Live chat connected.')).toBeInTheDocument();
    act(() => setLiveStatus('unavailable'));
    expect(screen.getByRole('button', { name: 'Reload messages' })).toBeInTheDocument();

    const user = userEvent.setup();
    const input = screen.getByRole('textbox', { name: 'Message' });
    await user.type(input, 'Keep this draft');
    await user.click(screen.getByRole('button', { name: 'Send' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not send the message.');
    expect(input).toHaveValue('Keep this draft');
    expect(screen.getByRole('button', { name: 'Send' })).toBeEnabled();
});

it('hides future text before its timestamp and rechecks when playback moves in either direction', async () => {
    const ordinary = { id: '1', text: 'Regular chat', created_at: createdAt };
    const future = { id: '2', text: 'Scene at 42 minutes', created_at: createdAt, video_timestamp_seconds: 2520 };
    vi.mocked(subscribeToMovieNight).mockReturnValue(vi.fn());
    vi.mocked(getMovieNightMessages).mockResolvedValue(page([future, ordinary]));

    const { rerender } = render(<MovieNightMessages nightId="3" playbackTime={1200} />);
    await screen.findByText(ordinary.text);
    expect(screen.queryByText(future.text)).not.toBeInTheDocument();

    rerender(<MovieNightMessages nightId="3" playbackTime={2520} />);
    expect(screen.getByText(future.text)).toBeInTheDocument();

    rerender(<MovieNightMessages nightId="3" playbackTime={1200} />);
    expect(screen.queryByText(future.text)).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Reveal spoiler' }));
    expect(screen.getByText(future.text)).toBeInTheDocument();
    rerender(<MovieNightMessages nightId="3" playbackTime={100} />);
    expect(screen.getByText(future.text)).toBeInTheDocument();
});

it('requires manual reveal for explicit spoilers even after their timestamp', async () => {
    const spoiler = { id: '1', text: 'Manually flagged', created_at: createdAt, video_timestamp_seconds: 20, is_spoiler: true };
    vi.mocked(subscribeToMovieNight).mockReturnValue(vi.fn());
    vi.mocked(getMovieNightMessages).mockResolvedValue(page([spoiler]));

    render(<MovieNightMessages nightId="4" playbackTime={30} />);

    const reveal = await screen.findByRole('button', { name: 'Reveal spoiler' });
    expect(screen.queryByText(spoiler.text)).not.toBeInTheDocument();
    await userEvent.click(reveal);
    expect(screen.getByText(spoiler.text)).toBeInTheDocument();
});

it('sends the current playback position and explicit spoiler flag', async () => {
    const spoiler = { id: '1', text: 'Manually flagged', created_at: createdAt, video_timestamp_seconds: 42.25, is_spoiler: true };
    vi.mocked(subscribeToMovieNight).mockReturnValue(vi.fn());
    vi.mocked(getMovieNightMessages).mockResolvedValue(page([]));
    vi.mocked(sendMovieNightMessage).mockResolvedValue(spoiler);
    const getPlaybackTime = vi.fn(() => 42.25);

    render(<MovieNightMessages nightId="5" canSend playbackTime={40} getPlaybackTime={getPlaybackTime} />);
    await screen.findByText('No messages yet.');
    expect(getPlaybackTime).not.toHaveBeenCalled();

    const user = userEvent.setup();
    const checkbox = screen.getByRole('checkbox', { name: 'Mark as spoiler' });
    await user.type(screen.getByRole('textbox', { name: 'Message' }), spoiler.text);
    await user.click(checkbox);
    await user.click(screen.getByRole('button', { name: 'Send' }));

    expect(sendMovieNightMessage).toHaveBeenCalledWith('5', {
        text: spoiler.text,
        video_timestamp_seconds: 42.25,
        is_spoiler: true,
    }, { signal: expect.any(AbortSignal) });
    expect(checkbox).not.toBeChecked();
    expect(screen.queryByText(spoiler.text)).not.toBeInTheDocument();
});
