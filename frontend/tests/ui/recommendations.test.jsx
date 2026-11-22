import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import App from '../../src/App';
import { useAuth } from '../../src/hooks/useAuth';
import api from '../../src/services/api';

vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));
vi.mock('../../src/services/api', () => ({ default: { get: vi.fn() } }));

afterEach(() => {
    vi.resetAllMocks();
    vi.unstubAllGlobals();
});

const movie = {
    title: 'The Rain',
    local_movie_id: '9007199254740993',
    recommendation: { reasons: [{ code: 'weather_match', message: 'Matches the current weather selection.' }] },
};
const baseWeather = { status: 'not_requested', condition: null, temperature_c: null };
const cityWeather = { status: 'available', condition: 'rainy', temperature_c: 14 };
const coordinates = { latitude: 33.89, longitude: 35.5 };

function result({ data = [movie], weather = baseWeather, personalization = 'general' } = {}) {
    return { data: { data, meta: { weather, personalization, empty_reason: 'no_eligible_candidates', warnings: [] } } };
}

function open(authenticated = true) {
    useAuth.mockReturnValue({ isAuthenticated: authenticated, loading: false });
    render(<MemoryRouter initialEntries={['/recommendations']}><App /></MemoryRouter>);
}

function submitCity(name) {
    fireEvent.change(screen.getByLabelText('City'), { target: { value: name } });
    fireEvent.click(screen.getByRole('button', { name: 'Use city' }));
}

it('loads without asking for location, renders server reasons, and links to movie details', async () => {
    const getCurrentPosition = vi.fn();
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition } });
    api.get.mockResolvedValue(result());
    open();

    expect(screen.getByRole('status')).toHaveTextContent('Loading recommendations');
    const details = await screen.findByRole('link', { name: 'View details for The Rain' });
    expect(details).toHaveAttribute('href', '/movies/local/9007199254740993');
    expect(screen.getByText('Matches the current weather selection.')).toBeInTheDocument();
    expect(screen.getByText('Showing recommendations without location.')).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledWith('/v1/recommendations', { params: undefined, signal: expect.any(AbortSignal) });
    expect(getCurrentPosition).not.toHaveBeenCalled();
});

it('uses Laravel city lookup and displays weather, and lets the viewer clear the location', async () => {
    api.get.mockResolvedValueOnce(result())
        .mockResolvedValueOnce({ data: { data: coordinates } })
        .mockResolvedValueOnce(result({ weather: cityWeather, personalization: 'personalized' }))
        .mockResolvedValueOnce(result());
    open();
    await screen.findByText('Showing recommendations without location.');

    submitCity('Beirut, Lebanon');
    expect(await screen.findByText('Weather for Beirut, Lebanon')).toBeInTheDocument();
    expect(screen.getByText('rainy')).toBeInTheDocument();
    expect(screen.getByText(/14 °C/)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Picked for you' })).toBeInTheDocument();
    expect(api.get).toHaveBeenNthCalledWith(2, '/v1/recommendations/city', {
        params: { city: 'Beirut, Lebanon' }, signal: expect.any(AbortSignal),
    });
    expect(api.get).toHaveBeenNthCalledWith(3, '/v1/recommendations', {
        params: coordinates, signal: expect.any(AbortSignal),
    });
    fireEvent.click(screen.getByRole('button', { name: 'Browse without location' }));
    expect(await screen.findByText('Showing recommendations without location.')).toBeInTheDocument();
});

it('preserves recommendations on permission denial and falls back for an unresolved city', async () => {
    const getCurrentPosition = vi.fn((_success, failure) => failure({ code: 1 }));
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition } });
    api.get.mockResolvedValueOnce(result()).mockResolvedValueOnce({ data: { data: null } })
        .mockResolvedValueOnce(result());
    open();
    await screen.findByRole('heading', { name: 'The Rain' });

    fireEvent.click(screen.getByRole('button', { name: 'Use my location' }));
    expect(screen.getByText(/Could not use your device location/)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'The Rain' })).toBeInTheDocument();

    submitCity('Unknown city');
    expect(await screen.findByText(/Could not confirm that city/)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'The Rain' })).toBeInTheDocument();
    expect(api.get).toHaveBeenLastCalledWith('/v1/recommendations', { params: null, signal: expect.any(AbortSignal) });
});

it('uses rounded device coordinates after a user requests location', async () => {
    const getCurrentPosition = vi.fn((success) => success({ coords: { latitude: 33.8938, longitude: 35.5018 } }));
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition } });
    api.get.mockResolvedValueOnce(result()).mockResolvedValueOnce(result({ weather: cityWeather }));
    open();
    await screen.findByText('Showing recommendations without location.');

    fireEvent.click(screen.getByRole('button', { name: 'Use my location' }));
    expect(await screen.findByText('Weather for your approximate device location')).toBeInTheDocument();
    expect(api.get).toHaveBeenLastCalledWith('/v1/recommendations', { params: coordinates, signal: expect.any(AbortSignal) });
});

it('distinguishes failed and empty recommendation results and can retry', async () => {
    api.get.mockRejectedValueOnce(new Error('Offline')).mockResolvedValueOnce(result({ data: [] }));
    open();

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load recommendations');
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(await screen.findByText('No eligible movies to recommend yet.')).toBeInTheDocument();
});

it('redirects signed-out viewers before requesting recommendations', async () => {
    open(false);
    expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeInTheDocument();
    expect(api.get).not.toHaveBeenCalled();
});
