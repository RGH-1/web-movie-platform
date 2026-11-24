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
