import { fireEvent, render, screen } from '@testing-library/react';
import { AxiosError } from 'axios';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import App from '../../src/App';
import { useAuth } from '../../src/hooks/useAuth';
import api from '../../src/services/api';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));

const originalAdapter = api.defaults.adapter;
const id = '9007199254740993';
const apiPath = `/v1/movie-nights/${id}`;
const detailsPath = `/movie-nights/${id}`;
const draft = {
    id,
    title: 'Weekend movies',
    starts_at: '2099-12-29T18:00:43.000000Z',
    timezone: 'Asia/Beirut',
    organizer: { id: '4', name: 'Morgan' },
    description: null,
    location: null,
    meeting_url: null,
    status: 'draft',
    viewer_role: 'organizer',
    permissions: { update: true, cancel: true },
    summaries: { participants: { count: 0 }, invitations: null, shortlist: null },
};

afterEach(() => {
    api.defaults.adapter = originalAdapter;
    vi.clearAllMocks();
});

function open(path = '/movie-nights', signedIn = true) {
    useAuth.mockReturnValue({ isAuthenticated: signedIn, loading: false });
    render(<MemoryRouter initialEntries={[path]}><App /></MemoryRouter>);
}

function response(config, data, status = 200) {
    return { config, data, status, headers: {} };
}

function reject(config, status, data = {}) {
    throw new AxiosError('Request failed', 'ERR_BAD_RESPONSE', config, null, response(config, data, status));
}

it('creates, schedules by editing, and cancels through the existing Laravel endpoints', async () => {
    let night = null;
    api.defaults.adapter = vi.fn(async (config) => {
        const { url, method } = config;
        if (url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (url === '/v1/movie-nights' && method === 'get') {
            return response(config, { data: [], meta: { current_page: 1, last_page: 1 } });
        }
        if (url === '/v1/movie-nights' && method === 'post') {
            const fields = JSON.parse(config.data);
            expect(fields).toEqual({
                title: draft.title, description: null, location: 'Room 2', meeting_url: 'https://example.com/meet',
                starts_at: new Date('2099-12-30T20:45').toISOString(),
                status: 'draft', timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            });
            night = { ...draft, ...fields };
            return response(config, { data: night }, 201);
        }
        if (url === apiPath && method === 'get') return response(config, { data: night });
        if (url === apiPath && method === 'patch') {
            const fields = JSON.parse(config.data);
            expect(fields).toEqual({ status: 'scheduled', location: null });
            night = { ...night, ...fields };
            return response(config, { data: night });
        }
        if (url === `${apiPath}/cancel` && method === 'post') {
            return response(config, { data: { ...night, status: 'cancelled', permissions: { update: false, cancel: false } } });
        }
        throw new Error(`Unexpected request: ${method} ${url}`);
    });

    open();
    expect(await screen.findByText("You don't have any movie nights yet.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Plan a movie night' }));
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: draft.title } });
    fireEvent.change(screen.getByLabelText('Date and time'), { target: { value: '2099-12-30T20:45' } });
    fireEvent.change(screen.getByLabelText('Location (optional)'), { target: { value: 'Room 2' } });
    fireEvent.change(screen.getByLabelText('Meeting link (optional)'), { target: { value: 'https://example.com/meet' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save movie night' }));

    await screen.findByRole('heading', { name: draft.title });
    expect(screen.getByRole('link', { name: 'Open meeting link' })).toHaveAttribute('href', 'https://example.com/meet');
    fireEvent.click(screen.getByRole('button', { name: 'Edit movie night' }));
    expect(screen.getByLabelText('Date and time')).toHaveValue('2099-12-30T20:45');
    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'scheduled' } });
    fireEvent.change(screen.getByLabelText('Location (optional)'), { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save movie night' }));
    expect(await screen.findByText('Movie night saved.')).toBeInTheDocument();
    expect(screen.getByText('Scheduled', { selector: 'dd' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel movie night' }));
    expect(screen.getByRole('heading', { name: 'Cancel this movie night?' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Keep movie night' }));
    expect(screen.queryByRole('button', { name: 'Confirm cancellation' })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel movie night' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm cancellation' }));
    expect(await screen.findByText('Movie night cancelled.')).toBeInTheDocument();
    expect(screen.getByText('Cancelled', { selector: 'dd' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Edit movie night' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel movie night' })).not.toBeInTheDocument();
    const writes = api.defaults.adapter.mock.calls.flatMap(([{ method, url }]) => method !== 'get' || url.endsWith('/sanctum/csrf-cookie') ? [method] : []);
    expect(writes).toEqual(['get', 'post', 'get', 'patch', 'get', 'post']);
});

it('keeps the form on screen and associates local and server validation with fields', async () => {
    api.defaults.adapter = vi.fn(async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.method === 'post') return reject(config, 422, { errors: { title: ['Server rejected this title.'] } });
        return response(config, { data: [], meta: { current_page: 1, last_page: 1 } });
    });
    open();
    fireEvent.click(await screen.findByRole('button', { name: 'Plan a movie night' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save movie night' }));
    expect(screen.getByLabelText('Title')).toHaveAccessibleDescription('Enter a title.');
    expect(screen.getByLabelText('Date and time')).toHaveAttribute('aria-invalid', 'true');
    expect(api.defaults.adapter).toHaveBeenCalledTimes(2);

    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Draft title' } });
    fireEvent.change(screen.getByLabelText('Date and time'), { target: { value: '2099-12-30T20:45' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save movie night' }));
    await screen.findByText('Server rejected this title.');
    expect(screen.getByLabelText('Title')).toHaveValue('Draft title');
    expect(screen.getByLabelText('Title')).toHaveAccessibleDescription('Server rejected this title.');
});

it('shows participant details without organizer controls, and gives unavailable tools honest labels', async () => {
    const participantNight = {
        ...draft, status: 'scheduled', viewer_role: 'participant',
        summaries: { ...draft.summaries, participants: { count: 2 } },
        permissions: { update: false, cancel: false },
    };
    api.defaults.adapter = async (config) => response(config, { data: participantNight });
    open(detailsPath);

    expect(await screen.findByRole('heading', { name: draft.title })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Morgan' })).toHaveAttribute('href', '/users/4');
    expect(screen.getByText('2 confirmed, excluding the organizer')).toBeInTheDocument();
    expect(screen.getByText('Asia/Beirut', { exact: false })).toHaveAttribute('dateTime', draft.starts_at);
    expect(screen.getByRole('region', { name: 'Calendar export' }))
        .toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add movie night to calendar' }))
        .toBeEnabled();
    expect(screen.getByRole('link', { name: 'Open watch room' })).toHaveAttribute('href', `${detailsPath}/watch-room`);
    expect(screen.queryByRole('button', { name: 'Edit movie night' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Cancel movie night' })).not.toBeInTheDocument();
});

it('handles loading, retry, and pagination for the movie-night list', async () => {
    api.defaults.adapter = vi.fn().mockRejectedValueOnce(new Error('Offline')).mockImplementation(async (config) => {
        const page = config.params.page;
        return response(config, {
            data: [{ ...draft, title: page === 1 ? draft.title : 'Second event' }],
            meta: { current_page: page, last_page: 2 },
        });
    });
    open();

    expect(screen.getByRole('status')).toHaveTextContent('Loading movie nights');
    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load your movie nights');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByRole('link', { name: draft.title })).toHaveAttribute('href', detailsPath);
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    expect(await screen.findByRole('link', { name: 'Second event' })).toBeInTheDocument();
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();
});

it('uses the same safe message for a missing or inaccessible movie-night ID', async () => {
    api.defaults.adapter = async (config) => reject(config, 404);
    open(detailsPath);
    expect(await screen.findByRole('alert')).toHaveTextContent('Movie night not found or unavailable to your account.');
    expect(screen.queryByRole('button', { name: 'Edit movie night' })).not.toBeInTheDocument();
});

const invitationPreview = {
    id: '17', movie_night_id: id, inviter_id: '4', status: 'pending',
    invitee: { id: '9', name: 'Avery' },
    movie_night_preview: {
        id, title: 'Friday movie night', starts_at: '2099-12-30T18:00:00.000000Z', timezone: 'Asia/Beirut',
        location: 'Campus lounge', description: 'Bring snacks.', meeting_url: 'https://example.com/meet', status: 'scheduled',
        organizer: { id: '4', name: 'Morgan' },
    },
};

function invitationGroups(invitation = invitationPreview) {
    return { pending: invitation.status === 'pending' ? [invitation] : [], accepted: invitation.status === 'accepted' ? [invitation] : [], declined: invitation.status === 'declined' ? [invitation] : [] };
}

it('renders the complete, limited preview for a pending invitation', async () => {
    api.defaults.adapter = async (config) => {
        if (config.url === apiPath) return reject(config, 404);
        if (config.url === `${apiPath}/invitations`) return response(config, { data: invitationGroups() });
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);

    expect(await screen.findByRole('heading', { name: 'Friday movie night' })).toBeInTheDocument();
    expect(screen.getByText('Morgan', { selector: 'strong' })).toBeInTheDocument();
    expect(screen.getByRole('time')).toHaveAttribute('dateTime', invitationPreview.movie_night_preview.starts_at);
    expect(screen.getByText('Campus lounge')).toBeInTheDocument();
    expect(screen.getByText('Bring snacks.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open meeting link' })).toHaveAttribute('href', 'https://example.com/meet');
    expect(screen.queryByText('Shortlist and voting')).not.toBeInTheDocument();
    expect(screen.queryByText('Loading movie night…')).not.toBeInTheDocument();
});

it.each([
    ['accepted', 'Accept invitation', 'Decline'],
    ['declined', 'Decline', 'Accept invitation'],
])('does not show a redundant %s preview action', async (rsvpStatus, hiddenAction, availableAction) => {
    const invitation = { ...invitationPreview, status: rsvpStatus };
    api.defaults.adapter = async (config) => {
        if (config.url === apiPath) return reject(config, 404);
        if (config.url === `${apiPath}/invitations`) return response(config, { data: invitationGroups(invitation) });
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    await screen.findByRole('heading', { name: 'Friday movie night' });
    expect(screen.queryByRole('button', { name: hiddenAction })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: availableAction })).toBeInTheDocument();
});

it('loads full details after accepting an invitation', async () => {
    let invitation = invitationPreview;
    let accepted = false;
    api.defaults.adapter = async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.url === apiPath && config.method === 'get') return accepted
            ? response(config, { data: { ...draft, status: 'scheduled', viewer_role: 'participant', permissions: { update: false, cancel: false } } })
            : reject(config, 404);
        if (config.url === `${apiPath}/invitations` && config.method === 'get') return response(config, { data: invitationGroups(invitation) });
        if (config.url === `${apiPath}/rsvp`) {
            const status = JSON.parse(config.data).status;
            invitation = { ...invitation, status };
            accepted = status === 'accepted';
            return response(config, { data: invitation });
        }
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    fireEvent.click(await screen.findByRole('button', { name: 'Accept invitation' }));
    expect(await screen.findByRole('heading', { name: draft.title })).toBeInTheDocument();
    expect(screen.queryByText('Campus lounge')).not.toBeInTheDocument();
});

it('keeps the limited preview visible after declining an invitation', async () => {
    let invitation = invitationPreview;
    api.defaults.adapter = async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.url === apiPath) return reject(config, 404);
        if (config.url === `${apiPath}/invitations` && config.method === 'get') return response(config, { data: invitationGroups(invitation) });
        if (config.url === `${apiPath}/rsvp`) { invitation = { ...invitation, status: JSON.parse(config.data).status }; return response(config, { data: invitation }); }
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    fireEvent.click(await screen.findByRole('button', { name: 'Decline' }));
    expect(await screen.findByText('Invitation: declined', { exact: false })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Friday movie night' })).toBeInTheDocument();
    expect(screen.queryByText('Event details')).not.toBeInTheDocument();
});

it('does not expose a private invitation preview to an unrelated user', async () => {
    api.defaults.adapter = async (config) => reject(config, 404);
    open(detailsPath);
    expect(await screen.findByRole('alert')).toHaveTextContent('Movie night not found or unavailable to your account.');
    expect(screen.queryByText('Friday movie night')).not.toBeInTheDocument();
    expect(screen.queryByText('Campus lounge')).not.toBeInTheDocument();
});

it('lets an organizer invite a follower and inspect invitation statuses', async () => {
    const accepted = { ...invitationPreview, id: '18', status: 'accepted', invitee: { id: '10', name: 'Rae' } };
    let groups = { ...invitationGroups(), accepted: [accepted], declined: [] };
    api.defaults.adapter = async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.url === apiPath) return response(config, { data: { ...draft, status: 'scheduled' } });
        if (config.url === `${apiPath}/invitations` && config.method === 'get') return response(config, { data: groups });
        if (config.url === `${apiPath}/invitations` && config.method === 'post') {
            expect(JSON.parse(config.data)).toEqual({ invitee_id: '11' });
            groups = { ...groups, pending: [...groups.pending, { ...invitationPreview, id: '19', invitee: { id: '11', name: 'Taylor' } }] };
            return response(config, { data: groups.pending.at(-1) }, 201);
        }
        if (config.url === '/v1/users/4/following' || config.url === '/v1/users/4/followers') return response(config, { data: [], meta: { current_page: 1, last_page: 1 } });
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    fireEvent.click(await screen.findByRole('button', { name: 'Invitations' }));
    expect(await screen.findByText('Avery')).toBeInTheDocument();
    expect(screen.getByText('Rae')).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Or invite exact user ID'), { target: { value: '11' } });
    fireEvent.click(screen.getByRole('button', { name: 'Invite' }));
    expect(await screen.findByText('Taylor')).toBeInTheDocument();
});

it('lets a participant propose a canonical catalog movie', async () => {
    const participant = { ...draft, status: 'scheduled', viewer_role: 'participant', permissions: { update: false, cancel: false } };
    const empty = { status: 'scheduled', voting_open: true, winning_movie_id: null, viewer_candidate_id: null, total_votes: 0, leading_candidate_ids: [], candidates: [] };
    const proposed = { ...empty, candidates: [{ id: '22', vote_count: 0, movie: { local_movie_id: '42', title: 'Canonical movie', is_active: true } }] };
    api.defaults.adapter = async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.url === apiPath) return response(config, { data: participant });
        if (config.url === `${apiPath}/candidates` && config.method === 'get') return response(config, { data: empty });
        if (config.url === '/v1/catalog/movies') return response(config, { data: [{ local_movie_id: '42', title: 'Canonical movie' }], meta: { current_page: 1, last_page: 1 } });
        if (config.url === `${apiPath}/candidates` && config.method === 'post') { expect(JSON.parse(config.data)).toEqual({ movie_id: '42' }); return response(config, { data: proposed }, 201); }
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    fireEvent.click(await screen.findByRole('button', { name: 'Shortlist and voting' }));
    await screen.findByText('No movies have been proposed.');
    fireEvent.change(screen.getByLabelText('Propose a catalog movie'), { target: { value: 'canonical' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Propose' }));
    expect(await screen.findByRole('button', { name: 'Vote' })).toBeInTheDocument();
    expect(screen.getByLabelText('Propose a catalog movie')).toHaveValue('');
    expect(screen.queryByRole('button', { name: 'Propose' })).not.toBeInTheDocument();
});

it('explains when a catalog search has no matching movies', async () => {
    const participant = { ...draft, status: 'scheduled', viewer_role: 'participant', permissions: { update: false, cancel: false } };
    const summary = { status: 'scheduled', voting_open: true, winning_movie_id: null, viewer_candidate_id: null, total_votes: 0, leading_candidate_ids: [], candidates: [] };
    api.defaults.adapter = async (config) => {
        if (config.url === apiPath) return response(config, { data: participant });
        if (config.url === `${apiPath}/candidates`) return response(config, { data: summary });
        if (config.url === '/v1/catalog/movies') return response(config, { data: [], meta: { current_page: 1, last_page: 1 } });
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    fireEvent.click(await screen.findByRole('button', { name: 'Shortlist and voting' }));
    await screen.findByText('No movies have been proposed.');
    fireEvent.change(screen.getByLabelText('Propose a catalog movie'), { target: { value: 'nothing' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    expect(await screen.findByText('No matching movies found.')).toBeInTheDocument();
});

it('shows vote totals and the selected ballot after a participant changes vote', async () => {
    const participant = { ...draft, status: 'scheduled', viewer_role: 'participant', permissions: { update: false, cancel: false } };
    const first = { id: '22', vote_count: 3, movie: { local_movie_id: '42', title: 'First movie', is_active: true } };
    const second = { id: '23', vote_count: 2, movie: { local_movie_id: '43', title: 'Second movie', is_active: true } };
    let summary = { status: 'scheduled', voting_open: true, winning_movie_id: null, viewer_candidate_id: null, total_votes: 5, leading_candidate_ids: ['22'], candidates: [first, second] };
    api.defaults.adapter = async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.url === apiPath) return response(config, { data: participant });
        if (config.url === `${apiPath}/candidates` && config.method === 'get') return response(config, { data: summary });
        if (config.url === `${apiPath}/vote`) { const candidateId = JSON.parse(config.data).candidate_id; summary = { ...summary, viewer_candidate_id: candidateId, total_votes: 6, candidates: summary.candidates.map((c) => ({ ...c, vote_count: c.id === candidateId ? 4 : 2 })) }; return response(config, { data: summary }); }
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    fireEvent.click(await screen.findByRole('button', { name: 'Shortlist and voting' }));
    expect(await screen.findByText('5 total votes')).toBeInTheDocument();
    fireEvent.click(screen.getAllByRole('button', { name: 'Vote' })[1]);
    expect(await screen.findByRole('button', { name: 'Your vote' })).toBeInTheDocument();
    expect(screen.getByText('6 total votes')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Finalize/ })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Vote' }));
    expect(await screen.findByRole('button', { name: 'Your vote' })).toBeInTheDocument();
});

it('allows organizers to finalize only a sole leader, or explicitly choose a tied winner', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const sole = { id: '22', vote_count: 4, movie: { local_movie_id: '42', title: 'Leader', is_active: true } };
    const tied = { id: '23', vote_count: 4, movie: { local_movie_id: '43', title: 'Tied leader', is_active: true } };
    let summary = { status: 'scheduled', voting_open: true, winning_movie_id: null, viewer_candidate_id: null, total_votes: 8, leading_candidate_ids: ['22'], candidates: [sole, tied] };
    const payloads = [];
    api.defaults.adapter = async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.url === apiPath) return response(config, { data: { ...draft, status: 'scheduled' } });
        if (config.url === `${apiPath}/candidates` && config.method === 'get') return response(config, { data: summary });
        if (config.url === `${apiPath}/finalize`) { payloads.push(JSON.parse(config.data)); summary = { ...summary, winning_movie_id: payloads.length === 1 ? '42' : '43', voting_open: false }; return response(config, { data: summary }); }
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    fireEvent.click(await screen.findByRole('button', { name: 'Shortlist and voting' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Finalize Leader' }));
    await screen.findByText('Winner finalized.');
    expect(payloads[0]).toEqual({});

    summary = { ...summary, voting_open: true, winning_movie_id: null, leading_candidate_ids: ['22', '23'] };
    fireEvent.click(screen.getByRole('button', { name: 'Shortlist and voting' }));
    fireEvent.click(screen.getByRole('button', { name: 'Shortlist and voting' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Finalize Tied leader' }));
    await screen.findByText('Winner finalized.');
    expect(payloads[1]).toEqual({ candidate_id: '23' });
});

it.each(['draft', 'finalized', 'cancelled'])('%s events prevent shortlist actions', async (status) => {
    const participant = { ...draft, status, viewer_role: 'participant', permissions: { update: false, cancel: false } };
    const summary = { status, voting_open: false, winning_movie_id: status === 'finalized' ? '42' : null, viewer_candidate_id: null, total_votes: 0, leading_candidate_ids: ['22'], candidates: [{ id: '22', vote_count: 0, movie: { local_movie_id: '42', title: 'Movie', is_active: true } }] };
    api.defaults.adapter = async (config) => {
        if (config.url === apiPath) return response(config, { data: participant });
        if (config.url === `${apiPath}/candidates`) return response(config, { data: summary });
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open(detailsPath);
    fireEvent.click(await screen.findByRole('button', { name: 'Shortlist and voting' }));
    expect(await screen.findByText('Movie')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Vote' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Finalize/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Search' })).not.toBeInTheDocument();
});

it('shows the invitation inbox, updates RSVP history, and refreshes My Events', async () => {
    let accepted = false;
    const received = {
        ...invitationPreview,
        movie_night_preview: { ...invitationPreview.movie_night_preview, title: 'Inbox movie night' },
    };
    api.defaults.adapter = async (config) => {
        if (config.url.endsWith('/sanctum/csrf-cookie')) return response(config, null, 204);
        if (config.url === '/v1/movie-nights') return response(config, {
            data: accepted ? [{ ...draft, status: 'scheduled', title: 'Inbox movie night', viewer_role: 'participant', permissions: { update: false, cancel: false } }] : [],
            meta: { current_page: 1, last_page: 1 },
        });
        if (config.url === '/v1/me/movie-night-invitations') return response(config, {
            data: [{ ...received, status: accepted ? 'accepted' : 'pending' }],
            meta: { current_page: 1, last_page: 1, total: 1 },
        });
        if (config.url === `${apiPath}/rsvp`) { accepted = JSON.parse(config.data).status === 'accepted'; return response(config, { data: { ...received, status: 'accepted' } }); }
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open();
    fireEvent.click(await screen.findByRole('tab', { name: 'My Invitations (1)' }));
    expect(await screen.findByRole('heading', { name: 'Inbox movie night' })).toBeInTheDocument();
    expect(screen.getByText('Invited by Morgan')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Accept' }));
    expect(await screen.findByRole('link', { name: 'View Movie Night' })).toHaveAttribute('href', detailsPath);
    fireEvent.click(screen.getByRole('tab', { name: 'My Events' }));
    expect(await screen.findByRole('link', { name: 'Inbox movie night' })).toHaveAttribute('href', detailsPath);
});

it('keeps cancelled invitations visible but read-only in invitation history', async () => {
    const cancelled = { ...invitationPreview, status: 'declined', movie_night_preview: { ...invitationPreview.movie_night_preview, status: 'cancelled' } };
    api.defaults.adapter = async (config) => {
        if (config.url === '/v1/movie-nights') return response(config, { data: [], meta: { current_page: 1, last_page: 1 } });
        if (config.url === '/v1/me/movie-night-invitations') return response(config, { data: [cancelled], meta: { current_page: 1, last_page: 1, total: 1 } });
        throw new Error(`Unexpected request: ${config.method} ${config.url}`);
    };
    open();
    fireEvent.click(await screen.findByRole('tab', { name: 'My Invitations (0)' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Declined' }));
    expect(await screen.findByText('Event: Cancelled (read-only)')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Decline' })).not.toBeInTheDocument();
});
