import {
    act, fireEvent, render, screen, waitFor, within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import {
    beforeEach, expect, it, vi,
} from 'vitest';

import WatchRoomPage from '../../src/pages/WatchRoomPage';
import { subscribeToMovieNight } from '../../src/services/movieNightBroadcast';
import {
    getMovieNight,
    getMovieNightVideo,
    getMovieNightVideoAssets,
    getMovieNightMessages,
    sendMovieNightMessage,
} from '../../src/services/movieNights';

vi.mock('../../src/services/movieNightBroadcast');
vi.mock('../../src/services/movieNights');

const nightId = '9007199254740993';

const night = {
    id: nightId,
    title: 'AB135 watch room',
    status: 'finalized',
    viewer_role: 'participant',
};

const selected = {
    movie_night_id: nightId,
    video: {
        id: '7',
        title: 'Watch Room Demo',
        duration_seconds: 60,
        stream_url: `/api/v1/movie-nights/${nightId}/video/stream`,
    },
};

const ordinary = {
    id: '1',
    movie_night_id: nightId,
    sender_id: '11',
    sender_name: 'Participant A',
    text: 'Ordinary message',
    video_timestamp_seconds: null,
    is_spoiler: false,
    created_at: '2026-11-25T10:00:00.000000Z',
};

const future = {
    ...ordinary,
    id: '2',
    text: 'Future scene at forty seconds',
    video_timestamp_seconds: 40,
};

const history = (data) => ({
    data,
    meta: { next_cursor: null },
});

function openRoom() {
    return render(
        <MemoryRouter initialEntries={[`/movie-nights/${nightId}/watch-room`]}>
            <Routes>
                <Route
                    path="/movie-nights/:nightId/watch-room"
                    element={<WatchRoomPage />}
                />
                <Route path="/login" element={<h1>Test login</h1>} />
            </Routes>
        </MemoryRouter>,
    );
}

async function player(view) {
    await within(view.container).findByRole('heading', {
        name: 'Watch Room: AB135 watch room',
    });

    const video = view.container.querySelector('video');
    expect(video).not.toBeNull();

    Object.defineProperty(video, 'duration', {
        configurable: true,
        value: 60,
    });
    fireEvent.loadedMetadata(video);

    return video;
}

function seek(video, seconds) {
    video.currentTime = seconds;
    fireEvent.seeked(video);
}

beforeEach(() => {
    vi.resetAllMocks();

    vi.mocked(getMovieNight).mockResolvedValue(night);
    vi.mocked(getMovieNightVideo).mockResolvedValue(selected);
    vi.mocked(getMovieNightVideoAssets).mockResolvedValue([]);
    vi.mocked(getMovieNightMessages)
        .mockResolvedValue(history([future, ordinary]));

    vi.mocked(subscribeToMovieNight).mockImplementation(
        (_id, { onStatus }) => {
            onStatus('live');
            return vi.fn();
        },
    );

    vi.mocked(sendMovieNightMessage).mockResolvedValue({
        ...ordinary,
        id: '3',
        text: 'Sent message',
    });
});

it('keeps playback and spoiler state independent between room instances', async () => {
    const first = openRoom();
    const second = openRoom();

    const firstVideo = await player(first);
    const secondVideo = await player(second);

    await within(first.container).findByText(ordinary.text);
    await within(second.container).findByText(ordinary.text);

    expect(within(first.container).queryByText(future.text))
        .not.toBeInTheDocument();
    expect(within(second.container).queryByText(future.text))
        .not.toBeInTheDocument();

    seek(firstVideo, 42);
    seek(secondVideo, 20);

    expect(firstVideo.currentTime).toBe(42);
    expect(secondVideo.currentTime).toBe(20);
    expect(within(first.container).getByText(future.text))
        .toBeInTheDocument();
    expect(within(second.container).queryByText(future.text))
        .not.toBeInTheDocument();

    seek(secondVideo, 45);
    seek(firstVideo, 10);

    expect(firstVideo.currentTime).toBe(10);
    expect(secondVideo.currentTime).toBe(45);
    expect(within(first.container).queryByText(future.text))
        .not.toBeInTheDocument();
    expect(within(second.container).getByText(future.text))
        .toBeInTheDocument();

    expect(firstVideo).toHaveAttribute('crossorigin', 'use-credentials');
    expect(new URL(firstVideo.src).pathname)
        .toBe(`/api/v1/movie-nights/${nightId}/video/stream`);
});

it('reads the actual video position when sending a message', async () => {
    const user = userEvent.setup();
    vi.mocked(getMovieNightMessages).mockResolvedValue(history([]));

    const sent = {
        ...ordinary,
        id: '3',
        text: 'Actual playhead',
        video_timestamp_seconds: 37.25,
    };
    vi.mocked(sendMovieNightMessage).mockResolvedValue(sent);

    const view = openRoom();
    const video = await player(view);
    await screen.findByText('No messages yet.');

    // Do not dispatch timeupdate: the send must read the video ref directly.
    video.currentTime = 37.25;

    await user.type(
        screen.getByRole('textbox', { name: 'Message' }),
        sent.text,
    );
    await user.click(screen.getByRole('button', { name: 'Send' }));

    await waitFor(() => expect(sendMovieNightMessage).toHaveBeenCalledWith(
        nightId,
        {
            text: sent.text,
            video_timestamp_seconds: 37.25,
            is_spoiler: false,
        },
        { signal: expect.any(AbortSignal) },
    ));
});

it('resets playback and hides future messages after a video-error retry', async () => {
    const view = openRoom();
    const video = await player(view);
    await screen.findByText(ordinary.text);

    seek(video, 45);
    expect(screen.getByText(future.text)).toBeInTheDocument();

    fireEvent.error(video);

    expect(await screen.findByRole('alert'))
        .toHaveTextContent('The selected video is currently unavailable');
    expect(view.container.querySelector('video')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));

    await waitFor(() => expect(view.container.querySelector('video'))
        .not.toBeNull());
    await screen.findByRole('button', { name: 'Reveal spoiler' });

    expect(view.container.querySelector('video').currentTime).toBe(0);
    expect(screen.queryByText(future.text)).not.toBeInTheDocument();
    expect(screen.getByText(/Playback:\s*0:00\s*\/\s*1:00/))
        .toBeInTheDocument();
});

it('supports untimestamped chat when no clip has been assigned', async () => {
    const user = userEvent.setup();

    vi.mocked(getMovieNightVideo).mockRejectedValue({
        response: { status: 404 },
    });
    vi.mocked(getMovieNightMessages).mockResolvedValue(history([]));

    openRoom();

    await screen.findByText(
        'No video clip has been selected for this movie night yet.',
    );
    expect(document.querySelector('video')).toBeNull();

    await user.type(
        screen.getByRole('textbox', { name: 'Message' }),
        'Before the clip is assigned',
    );
    await user.click(screen.getByRole('button', { name: 'Send' }));

    await waitFor(() => expect(sendMovieNightMessage).toHaveBeenCalledWith(
        nightId,
        {
            text: 'Before the clip is assigned',
            video_timestamp_seconds: null,
            is_spoiler: false,
        },
        { signal: expect.any(AbortSignal) },
    ));
});

it('recovers from an initial video-metadata failure', async () => {
    vi.mocked(getMovieNightVideo).mockRejectedValueOnce({
        response: { status: 503 },
    });

    const view = openRoom();

    expect(await screen.findByRole('alert'))
        .toHaveTextContent('The selected video is currently unavailable');

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));

    await player(view);
    await screen.findByText(ordinary.text);

    expect(getMovieNightVideo).toHaveBeenCalledTimes(2);
});