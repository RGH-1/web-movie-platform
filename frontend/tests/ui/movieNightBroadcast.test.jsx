import {
    afterEach, beforeEach, expect, it, vi,
} from 'vitest';

const mocks = vi.hoisted(() => ({
    Pusher: vi.fn(),
    Echo: vi.fn(),
    post: vi.fn(),
}));

vi.mock('pusher-js', () => ({ default: mocks.Pusher }));
vi.mock('laravel-echo', () => ({ default: mocks.Echo }));
vi.mock('../../src/services/api', () => ({
    default: { post: mocks.post },
}));

import { subscribeToMovieNight } from '../../src/services/movieNightBroadcast';

let options;
let callbacks;
let connection;
let channel;
let echo;

beforeEach(() => {
    vi.resetAllMocks();

    vi.stubEnv('VITE_REVERB_APP_KEY', 'u135-test-key');
    vi.stubEnv('VITE_REVERB_HOST', '127.0.0.1');
    vi.stubEnv('VITE_REVERB_PORT', '8080');
    vi.stubEnv('VITE_REVERB_SCHEME', 'http');

    callbacks = {};

    connection = {
        bind: vi.fn((_event, callback) => {
            callbacks.state = callback;
        }),
        unbind: vi.fn(),
    };

    channel = {
        listen: vi.fn((_event, callback) => {
            callbacks.message = callback;
            return channel;
        }),
        subscribed: vi.fn((callback) => {
            callbacks.subscribed = callback;
            return channel;
        }),
        error: vi.fn((callback) => {
            callbacks.error = callback;
            return channel;
        }),
        stopListening: vi.fn(),
    };

    echo = {
        private: vi.fn(() => channel),
        leave: vi.fn(),
        disconnect: vi.fn(),
    };

    mocks.Pusher.mockImplementation(function (_key, configuration) {
        options = configuration;
        return { connection };
    });

    mocks.Echo.mockImplementation(function () {
        return echo;
    });

    mocks.post.mockResolvedValue({ data: { auth: 'test-authentication' } });
});

afterEach(() => {
    vi.unstubAllEnvs();
});

it('authorizes the private room through the existing API client', async () => {
    const refresh = vi.fn();
    const onStatus = vi.fn();
    const controller = new AbortController();

    const leave = subscribeToMovieNight(
        '9',
        { refresh, onStatus },
        controller.signal,
    );

    expect(mocks.Pusher).toHaveBeenCalledWith(
        'u135-test-key',
        expect.objectContaining({
            wsHost: '127.0.0.1',
            wsPort: 8080,
            forceTLS: false,
        }),
    );
    expect(echo.private).toHaveBeenCalledWith('movie-nights.9');
    expect(channel.listen).toHaveBeenCalledWith('.message.created', refresh);

    const callback = vi.fn();

    await options.channelAuthorization.customHandler({
        socketId: '123.456',
        channelName: 'private-movie-nights.9',
    }, callback);

    expect(mocks.post).toHaveBeenCalledWith(
        '/v1/broadcasting/auth',
        {
            socket_id: '123.456',
            channel_name: 'private-movie-nights.9',
        },
        { signal: controller.signal },
    );
    expect(callback).toHaveBeenCalledWith(
        null,
        { auth: 'test-authentication' },
    );

    callbacks.subscribed();
    expect(onStatus).toHaveBeenLastCalledWith('live');
    expect(refresh).toHaveBeenCalledTimes(1);

    callbacks.message();
    expect(refresh).toHaveBeenCalledTimes(2);

    leave();

    expect(connection.unbind)
        .toHaveBeenCalledWith('state_change', callbacks.state);
    expect(channel.stopListening)
        .toHaveBeenCalledWith('.message.created', refresh);
    expect(echo.leave).toHaveBeenCalledWith('movie-nights.9');
    expect(echo.disconnect).toHaveBeenCalledOnce();
});

it('refreshes history after resubscription and reports connection failures', () => {
    const refresh = vi.fn();
    const onStatus = vi.fn();

    subscribeToMovieNight(
        '9',
        { refresh, onStatus },
        new AbortController().signal,
    );

    callbacks.subscribed();
    expect(refresh).toHaveBeenCalledTimes(1);

    callbacks.state({ current: 'disconnected' });
    expect(onStatus).toHaveBeenLastCalledWith('unavailable');

    callbacks.state({ current: 'connected' });
    expect(onStatus).toHaveBeenLastCalledWith('connecting');

    callbacks.subscribed();
    expect(onStatus).toHaveBeenLastCalledWith('live');
    expect(refresh).toHaveBeenCalledTimes(2);

    callbacks.error();
    expect(onStatus).toHaveBeenLastCalledWith('unavailable');
    expect(refresh).toHaveBeenCalledTimes(3);
});

it('passes authorization failures back to the transport', async () => {
    const failure = { response: { status: 403 } };
    mocks.post.mockRejectedValueOnce(failure);

    subscribeToMovieNight(
        '9',
        { refresh: vi.fn(), onStatus: vi.fn() },
        new AbortController().signal,
    );

    const callback = vi.fn();

    await options.channelAuthorization.customHandler({
        socketId: '123.456',
        channelName: 'private-movie-nights.9',
    }, callback);

    expect(callback).toHaveBeenCalledWith(failure, null);
});

it('offers the unavailable state when Reverb configuration is missing', () => {
    vi.stubEnv('VITE_REVERB_APP_KEY', '');

    const onStatus = vi.fn();
    const leave = subscribeToMovieNight(
        '9',
        { refresh: vi.fn(), onStatus },
        new AbortController().signal,
    );

    expect(onStatus).toHaveBeenCalledWith('unavailable');
    expect(mocks.Pusher).not.toHaveBeenCalled();
    expect(mocks.Echo).not.toHaveBeenCalled();
    expect(() => leave()).not.toThrow();
});