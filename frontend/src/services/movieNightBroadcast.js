import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import api from './api';

export function subscribeToMovieNight(nightId, refresh, unavailable, signal) {
    const { VITE_REVERB_APP_KEY: key, VITE_REVERB_HOST: host,
        VITE_REVERB_PORT: port, VITE_REVERB_SCHEME: scheme } = import.meta.env;

    if (!key || !host) {
        unavailable();
        return () => {};
    }

    const forceTLS = scheme === 'https';
    const client = new Pusher(key, {
        cluster: 'mt1',
        wsHost: host,
        wsPort: Number(port || 80),
        wssPort: Number(port || 443),
        forceTLS,
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            customHandler: async ({ socketId, channelName }, callback) => {
                try {
                    const { data } = await api.post('/v1/broadcasting/auth', {
                        socket_id: socketId,
                        channel_name: channelName,
                    }, { signal });
                    callback(null, data);
                } catch (error) {
                    callback(error, null);
                }
            },
        },
    });
    const echo = new Echo({ broadcaster: 'reverb', client, withoutInterceptors: true });
    const channelName = `movie-nights.${nightId}`;
    const channel = echo.private(channelName)
        .listen('.message.created', refresh)
        .subscribed(refresh)
        .error(unavailable);
    client.connection.bind('unavailable', unavailable);

    return () => {
        client.connection.unbind('unavailable', unavailable);
        channel.stopListening('.message.created', refresh);
        echo.leave(channelName);
        echo.disconnect();
    };
}
