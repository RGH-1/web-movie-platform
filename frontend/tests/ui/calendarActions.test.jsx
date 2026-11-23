import {
    act, fireEvent, render, screen, waitFor,
} from '@testing-library/react';
import {
    MemoryRouter, Route, Routes,
} from 'react-router-dom';
import { AxiosError } from 'axios';
import {
    afterEach, beforeEach, expect, it, vi,
} from 'vitest';

import MovieNightDetailsPage from '../../src/pages/MovieNightDetailsPage';
import MovieDetailsPage from '../../src/pages/MovieDetailsPage';
import api from '../../src/services/api';
import { downloadCalendar } from '../../src/utils/downloadCalendar.js';

vi.mock('../../src/utils/downloadCalendar.js', () => ({
    downloadCalendar: vi.fn(),
}));

const originalAdapter = api.defaults.adapter;
const id = '9007199254740993';
const apiPath = `/v1/movie-nights/${id}`;

const night = {
    id,
    title: 'Calendar integration night',
    organizer: { id: '7', name: 'Organizer' },
    description: 'Bring snacks.',
    starts_at: '2099-12-29T18:00:43.000000Z',
    timezone: 'Asia/Beirut',
    location: 'Room 2',
    meeting_url: 'https://example.test/meeting',
    status: 'scheduled',
    viewer_role: 'organizer',
    permissions: { update: true, cancel: true },
    summaries: {
        participants: { count: 0 },
        invitations: null,
        shortlist: null,
    },
    winning_movie_id: null,
    winning_movie: null,
    updated_at: '2026-11-23T10:00:00.000000Z',
};

let respond;

function response(config, data, status = 200) {
    const result = {
        config,
        data,
        status,
        headers: {},
        statusText: String(status),
    };

    if (status >= 400) {
        throw new AxiosError(
            'Test request failed',
            'ERR_BAD_RESPONSE',
            config,
            null,
            result,
        );
    }

    return result;
}

function open() {
    return render(
        <MemoryRouter initialEntries={[`/movie-nights/${id}`]}>
            <Routes>
                <Route
                    path="/movie-nights/:nightId"
                    element={<MovieNightDetailsPage />}
                />
                <Route path="/login" element={<h1>Test login</h1>} />
                <Route path="/profile" element={<h1>Test profile</h1>} />
            </Routes>
        </MemoryRouter>,
    );
}

beforeEach(() => {
    respond = async (config) => {
        if (config.method === 'get' && config.url === apiPath) {
            return response(config, { data: night });
        }

        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };

    api.defaults.adapter = vi.fn((config) => respond(config));
});

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.clearAllMocks();
});

it('exports current participant details through the existing service', async () => {
    let reads = 0;

    respond = async (config) => {
        reads++;

        return response(config, {
            data: {
                ...night,
                viewer_role: 'participant',
                permissions: { update: false, cancel: false },
                starts_at: reads === 1
                    ? night.starts_at
                    : '2099-12-29T21:00:00Z',
            },
        });
    };

    open();

    fireEvent.click(await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    }));

    await screen.findByText(/Calendar file prepared/);

    expect(api.defaults.adapter).toHaveBeenCalledTimes(2);
    expect(api.defaults.adapter.mock.calls[1][0])
        .toMatchObject({ method: 'get', url: apiPath });
    expect(api.defaults.adapter.mock.calls[1][0].signal)
        .toBeInstanceOf(AbortSignal);

    expect(downloadCalendar).toHaveBeenCalledWith(
        expect.stringContaining('DTSTART:20991229T210000Z'),
        `movie-night-${id}.ics`,
    );

    expect(screen.queryByRole('button', { name: 'Edit movie night' }))
        .not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel movie night' }))
        .not.toBeInTheDocument();
});

it('blocks duplicate exports and organizer mutations while refreshing', async () => {
    let reads = 0;
    let finish;

    respond = (config) => {
        reads++;

        if (reads === 1) {
            return Promise.resolve(response(config, { data: night }));
        }

        return new Promise((resolve) => {
            finish = () => resolve(response(config, { data: night }));
        });
    };

    open();

    const button = await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    });

    fireEvent.click(button);
    fireEvent.click(button);

    expect(screen.getByRole('button', {
        name: 'Preparing calendar...',
    })).toBeDisabled();
    expect(screen.getByRole('button', {
        name: 'Edit movie night',
    })).toBeDisabled();
    expect(screen.getByRole('button', {
        name: 'Cancel movie night',
    })).toBeDisabled();

    await waitFor(() => expect(reads).toBe(2));
    await act(async () => finish());

    await screen.findByText(/Calendar file prepared/);

    expect(downloadCalendar).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', {
        name: 'Edit movie night',
    })).toBeEnabled();
    expect(screen.getByRole('button', {
        name: 'Cancel movie night',
    })).toBeEnabled();
});

it('disables calendar export while editing or confirming cancellation', async () => {
    open();

    const calendar = await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    });

    fireEvent.click(screen.getByRole('button', {
        name: 'Edit movie night',
    }));
    expect(calendar).toBeDisabled();

    fireEvent.click(screen.getByRole('button', {
        name: 'Close form',
    }));
    expect(calendar).toBeEnabled();

    fireEvent.click(screen.getByRole('button', {
        name: 'Cancel movie night',
    }));
    expect(calendar).toBeDisabled();

    fireEvent.click(screen.getByRole('button', {
        name: 'Keep movie night',
    }));
    expect(calendar).toBeEnabled();
    expect(api.defaults.adapter).toHaveBeenCalledTimes(1);
});

it('removes stale details when participant access is revoked', async () => {
    let reads = 0;

    respond = async (config) => {
        if (++reads === 1) return response(config, { data: night });
        return response(config, {}, 404);
    };

    open();

    fireEvent.click(await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    }));

    expect(await screen.findByRole('alert'))
        .toHaveTextContent('Movie night not found or unavailable to your account');
    expect(screen.queryByRole('heading', { name: night.title }))
        .not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit movie night' }))
        .not.toBeInTheDocument();
    expect(downloadCalendar).not.toHaveBeenCalled();
});

it('preserves the existing login redirect after an export receives 401', async () => {
    let reads = 0;

    respond = async (config) => {
        if (++reads === 1) return response(config, { data: night });
        return response(config, {}, 401);
    };

    open();

    fireEvent.click(await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    }));

    await screen.findByRole('heading', { name: 'Test login' });
    expect(downloadCalendar).not.toHaveBeenCalled();
});

it('preserves profile-completion guidance after an export receives 403', async () => {
    let reads = 0;

    respond = async (config) => {
        if (++reads === 1) return response(config, { data: night });

        return response(config, {
            code: 'PROFILE_INCOMPLETE',
            incomplete_fields: ['name'],
        }, 403);
    };

    open();

    fireEvent.click(await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    }));

    await screen.findByRole('heading', { name: 'Complete your profile' });
    expect(screen.queryByRole('heading', { name: night.title }))
        .not.toBeInTheDocument();
    expect(downloadCalendar).not.toHaveBeenCalled();
});

it('uses fresh cancelled status without exporting stale scheduling data', async () => {
    let reads = 0;

    respond = async (config) => response(config, {
        data: ++reads === 1 ? night : {
            ...night,
            status: 'cancelled',
            permissions: { update: false, cancel: false },
        },
    });

    open();

    fireEvent.click(await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    }));

    await screen.findByText(
        'Cancelled movie nights cannot be added to a calendar.',
    );

    expect(downloadCalendar).not.toHaveBeenCalled();
    expect(screen.queryByRole('button', { name: 'Cancel movie night' }))
        .not.toBeInTheDocument();
});

it.each(['draft', 'cancelled'])(
    'does not offer export for %s nights',
    async (status) => {
        respond = async (config) => response(config, {
            data: { ...night, status },
        });

        open();
        await screen.findByRole('heading', { name: night.title });

        expect(screen.queryByRole('button', {
            name: 'Add movie night to calendar',
        })).not.toBeInTheDocument();
    },
);

it('retains details after a network failure and supports retry', async () => {
    let reads = 0;

    respond = async (config) => {
        if (++reads === 2) throw new Error('Offline');
        return response(config, { data: night });
    };

    open();

    fireEvent.click(await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not prepare');
    expect(screen.getByRole('heading', { name: night.title }))
        .toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Edit movie night' }))
        .toBeEnabled();
    expect(downloadCalendar).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', {
        name: 'Add movie night to calendar',
    }));

    await screen.findByText(/Calendar file prepared/);
    expect(downloadCalendar).toHaveBeenCalledTimes(1);
});

it('does not download after navigation unmounts the pending action', async () => {
    let reads = 0;
    let finish;

    respond = (config) => {
        if (++reads === 1) {
            return Promise.resolve(response(config, { data: night }));
        }

        return new Promise((resolve) => {
            finish = () => resolve(response(config, { data: night }));
        });
    };

    const view = open();

    fireEvent.click(await screen.findByRole('button', {
        name: 'Add movie night to calendar',
    }));

    await waitFor(() => expect(reads).toBe(2));
    view.unmount();
    await act(async () => finish());

    expect(downloadCalendar).not.toHaveBeenCalled();
});

it('exports an all-day release from the existing movie details component', async () => {
    render(<MovieDetailsPage movie={{
        tmdb_id: 123,
        title: 'Future release',
        release_date: '2099-12-31',
    }} />);

    fireEvent.click(screen.getByRole('button', {
        name: 'Add release to calendar',
    }));

    await screen.findByText(/Calendar file prepared/);

    expect(downloadCalendar).toHaveBeenCalledWith(
        expect.stringContaining('DTSTART;VALUE=DATE:20991231'),
        'release-tmdb-123.ics',
    );
    expect(downloadCalendar.mock.calls[0][0])
        .toContain('DTEND;VALUE=DATE:21000101');
    expect(api.defaults.adapter).not.toHaveBeenCalled();
});

it.each([null, '2026-02-30', '2000-01-01'])(
    'hides release export for unavailable date %s',
    (release_date) => {
        render(<MovieDetailsPage movie={{
            tmdb_id: 123,
            title: 'Unavailable release',
            release_date,
        }} />);

        expect(screen.queryByRole('button', {
            name: 'Add release to calendar',
        })).not.toBeInTheDocument();
    },
);