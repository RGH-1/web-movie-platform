import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ReviewSentiment from '../../src/components/ReviewSentiment.jsx';
import MovieReviewList from '../../src/components/MovieReviewList.jsx';
import MovieReviewsPanel from '../../src/components/MovieReviewsPanel.jsx';
import PublicProfilePage from '../../src/pages/PublicProfilePage.jsx';
import { AuthContext } from '../../src/context/AuthContext.jsx';
import api from '../../src/services/api.js';

const reviewUrl = '/v1/catalog/movies/42/reviews';

const review = {
    id: '17',
    movie_id: '42',
    author: { id: '8', name: 'Review author' },
    rating: 4,
    review_text: 'Original written review.',
    sentiment_status: 'pending',
    sentiment_label: null,
    sentiment_score: null,
};

let originalAdapter;
let visibility;

beforeEach(() => {
    originalAdapter = api.defaults.adapter;
    visibility = vi.spyOn(document, 'visibilityState', 'get')
        .mockReturnValue('visible');
});

afterEach(() => {
    cleanup();
    api.defaults.adapter = originalAdapter;

    if (vi.isFakeTimers()) {
        vi.clearAllTimers();
        vi.useRealTimers();
    }

    vi.restoreAllMocks();
});

function reply(config, data) {
    return {
        config,
        data,
        status: 200,
        statusText: '200',
        headers: {},
    };
}

function list(row = review) {
    return {
        data: [row],
        meta: {
            current_page: 1,
            last_page: 1,
            rating_count: 1,
            rating_average: row.rating,
        },
    };
}

async function timedRender(element) {
    let view;

    await act(async () => {
        view = render(<MemoryRouter>{element}</MemoryRouter>);
    });

    return view;
}

it.each([
    ['positive', 'completed', 'positive', 'Written text', 'Positive'],
    ['neutral', 'completed', 'neutral', 'Written text', 'Neutral'],
    ['negative', 'completed', 'negative', 'Written text', 'Negative'],
    ['pending', 'pending', 'positive', 'Written text', 'Pending'],
    ['failed', 'failed', 'positive', 'Written text', 'Unavailable'],
    ['unknown label', 'completed', 'unexpected', 'Written text', 'Unavailable'],
    ['legacy fields', undefined, undefined, 'Written text', 'Unavailable'],
    ['empty text', 'completed', 'positive', null, 'Not applicable'],
    ['blank text', 'pending', null, '   ', 'Not applicable'],
])('renders %s without inferring a classification', (
    _name,
    status,
    label,
    text,
    expected,
) => {
    render(
        <ReviewSentiment review={{
            rating: 1,
            sentiment_score: -1,
            review_text: text,
            sentiment_status: status,
            sentiment_label: label,
        }} />,
    );

    expect(
        screen.getByText(`Text sentiment: ${expected}`),
    ).toBeInTheDocument();
});

it.each([
    ['completed', 'negative', 'Negative'],
    ['failed', null, 'Unavailable'],
])('refreshes pending cards to %s and stops polling', async (
    status,
    label,
    expected,
) => {
    vi.useFakeTimers();

    let calls = 0;

    api.defaults.adapter = async (config) => {
        expect(config.url).toBe(reviewUrl);
        expect(config.method).toBe('get');

        calls += 1;

        return reply(config, list(calls === 1 ? review : {
            ...review,
            sentiment_status: status,
            sentiment_label: label,
        }));
    };

    await timedRender(<MovieReviewList movieId="42" />);

    expect(screen.getByText('Text sentiment: Pending')).toBeInTheDocument();

    await act(async () => {
        await vi.advanceTimersByTimeAsync(5000);
    });

    expect(
        screen.getByText(`Text sentiment: ${expected}`),
    ).toBeInTheDocument();

    await act(async () => {
        await vi.advanceTimersByTimeAsync(60000);
    });

    expect(calls).toBe(2);
});

it('bounds automatic refreshes and permits a manual refresh afterward', async () => {
    vi.useFakeTimers();

    let calls = 0;

    api.defaults.adapter = async (config) => {
        calls += 1;
        return reply(config, list());
    };

    await timedRender(<MovieReviewList movieId="42" />);

    for (let index = 0; index < 6; index += 1) {
        await act(async () => {
            await vi.advanceTimersByTimeAsync(5000);
        });
    }

    expect(calls).toBe(7);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(60000);
    });

    expect(calls).toBe(7);

    await act(async () => {
        fireEvent.click(screen.getByRole('button', {
            name: 'Refresh reviews',
        }));
    });

    expect(calls).toBe(8);
});

it('cancels scheduled refreshes and the request signal on unmount', async () => {
    vi.useFakeTimers();

    let calls = 0;
    let signal;

    api.defaults.adapter = async (config) => {
        calls += 1;
        signal = config.signal;
        return reply(config, list());
    };

    const view = await timedRender(<MovieReviewList movieId="42" />);

    view.unmount();

    expect(signal.aborted).toBe(true);

    await act(async () => {
        await vi.advanceTimersByTimeAsync(30000);
    });

    expect(calls).toBe(1);
});

it('stops automatic refreshes when the tab is hidden', async () => {
    vi.useFakeTimers();

    let calls = 0;

    api.defaults.adapter = async (config) => {
        calls += 1;
        return reply(config, list());
    };

    await timedRender(<MovieReviewList movieId="42" />);

    visibility.mockReturnValue('hidden');

    await act(async () => {
        await vi.advanceTimersByTimeAsync(30000);
    });

    expect(calls).toBe(1);
});

it('preserves loaded cards after refresh failure and permits retry', async () => {
    let row = {
        ...review,
        sentiment_status: 'completed',
        sentiment_label: 'positive',
    };

    let offline = false;

    api.defaults.adapter = async (config) => {
        expect(config.url).toBe(reviewUrl);

        if (offline) throw new Error('Offline');

        return reply(config, list(row));
    };

    render(
        <MemoryRouter>
            <MovieReviewList movieId="42" />
        </MemoryRouter>,
    );

    await screen.findByText('Text sentiment: Positive');

    offline = true;

    fireEvent.click(screen.getByRole('button', {
        name: 'Refresh reviews',
    }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
        'Could not refresh reviews. Showing the last loaded reviews.',
    );

    expect(screen.getByText('Text sentiment: Positive')).toBeInTheDocument();

    offline = false;
    row = { ...row, sentiment_label: 'negative' };

    fireEvent.click(screen.getByRole('button', {
        name: 'Refresh reviews',
    }));

    expect(
        await screen.findByText('Text sentiment: Negative'),
    ).toBeInTheDocument();
});

it('updates sentiment without overwriting an active edit draft', async () => {
    vi.useFakeTimers();

    let calls = 0;

    api.defaults.adapter = async (config) => {
        expect(config.url).toBe(reviewUrl);

        calls += 1;

        return reply(config, list(calls === 1 ? review : {
            ...review,
            sentiment_status: 'completed',
            sentiment_label: 'positive',
        }));
    };

    await timedRender(
        <AuthContext.Provider value={{
            user: { id: 8 },
            loading: false,
            profileCompletion: null,
        }}>
            <MovieReviewsPanel movie={{
                tmdb_id: 597,
                local_movie_id: '42',
                viewer_review: review,
            }} />
        </AuthContext.Provider>,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Edit review' }));

    fireEvent.change(screen.getByLabelText('Review (optional)'), {
        target: { value: 'My unsaved draft.' },
    });

    await act(async () => {
        await vi.advanceTimersByTimeAsync(5000);
    });

    expect(screen.getByText('Text sentiment: Positive')).toBeInTheDocument();
    expect(screen.getByLabelText('Review (optional)')).toHaveValue(
        'My unsaved draft.',
    );
    expect(
        screen.getByRole('radio', { name: '4 out of 5' }),
    ).toBeChecked();
});

it('refreshes sentiment on public-profile review cards', async () => {
    let completed = false;

    api.defaults.adapter = async (config) => reply(config, {
        data: {
            id: '8',
            name: 'Review author',
            statistics: { reviews_count: 1, public_lists_count: 0 },
            reviews: [{
                ...review,
                sentiment_status: completed ? 'completed' : 'pending',
                sentiment_label: completed ? 'neutral' : null,
            }],
            public_lists: [],
        },
    });

    render(
        <MemoryRouter initialEntries={['/users/8']}>
            <AuthContext.Provider value={{
                user: null,
                isAuthenticated: false,
                loading: false,
            }}>
                <Routes>
                    <Route
                        path="/users/:userId"
                        element={<PublicProfilePage />}
                    />
                </Routes>
            </AuthContext.Provider>
        </MemoryRouter>,
    );

    await screen.findByText('Text sentiment: Pending');

    completed = true;

    fireEvent.click(screen.getByRole('button', {
        name: 'Refresh public reviews',
    }));

    expect(
        await screen.findByText('Text sentiment: Neutral'),
    ).toBeInTheDocument();

    expect(screen.getByRole('link', { name: 'View movie' }))
        .toHaveAttribute('href', '/movies/local/42');
});