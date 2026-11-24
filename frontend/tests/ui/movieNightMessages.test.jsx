import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it, vi } from 'vitest';
import MovieNightMessages from '../../src/components/MovieNightMessages';
import { subscribeToMovieNight } from '../../src/services/movieNightBroadcast';
import { getMovieNightMessages, sendMovieNightMessage } from '../../src/services/movieNights';

vi.mock('../../src/services/movieNightBroadcast');
vi.mock('../../src/services/movieNights');

it('reloads across cursors after reconnect and renders each message once', async () => {
    const first = { id: '9', text: 'Before disconnect' };
    const missed = { id: '10', text: 'While offline' };
    const sent = { id: '11', text: 'From this browser' };
    const page = (data, next_cursor = null) => ({ data, meta: { next_cursor } });
    let notify;
    const leave = vi.fn();
    vi.mocked(subscribeToMovieNight).mockImplementation((_id, refresh) => {
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
    expect(screen.getAllByRole('listitem').map((item) => item.textContent))
        .toEqual([first.text, missed.text, sent.text]);

    unmount();
    expect(leave).toHaveBeenCalledOnce();
});
