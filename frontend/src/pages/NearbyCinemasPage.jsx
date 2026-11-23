import { useEffect, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import CinemaMap from '../components/CinemaMap';
import ProfileCompletionNotice from '../components/ProfileCompletionNotice';
import { getNearbyCinemas } from '../services/cinemas';
import { resolveRecommendationCity } from '../services/recommendations';
import { completionFromError } from '../utils/profileCompletion';

const SEARCH_RADIUS = 10000;

function directionsUrl({ latitude, longitude }) {
    const params = new URLSearchParams({
        api: '1',
        destination: `${latitude},${longitude}`,
    });

    return `https://www.google.com/maps/dir/?${params}`;
}
export default function NearbyCinemasPage() {
    const location = useLocation();
    const [city, setCity] = useState('');
    const [selection, setSelection] = useState(null);
    const [result, setResult] = useState(null);
    const [locating, setLocating] = useState(false);
    const [locationError, setLocationError] = useState('');

    useEffect(() => {
        if (!selection) return;

        const controller = new AbortController();
        const { signal } = controller;

        async function search() {
            try {
                const coordinates = selection.coordinates
                    ?? await resolveRecommendationCity(selection.city, { signal });

                if (signal.aborted) return;

                if (!coordinates) {
                    setResult({ selection, cityUnavailable: true });
                    return;
                }

                const cinemas = await getNearbyCinemas({
                    ...coordinates,
                    radius: SEARCH_RADIUS,
                }, { signal });

                if (!signal.aborted) setResult({ selection, coordinates, cinemas });
            } catch (error) {
                if (!signal.aborted) setResult({ selection, error });
            }
        }

        search();
        return () => controller.abort();
    }, [selection]);

    function submitCity(event) {
        event.preventDefault();
        const name = city.trim();

        if (!name) {
            setLocationError('Enter a city name.');
            return;
        }

        setLocationError('');
        setSelection({ city: name });
    }

    function useDeviceLocation() {
        setLocationError('');

        if (!navigator.geolocation) {
            setLocationError('Device location is unavailable. Enter a city instead.');
            return;
        }

        setLocating(true);
        navigator.geolocation.getCurrentPosition(({ coords }) => {
            setLocating(false);
            setSelection({
                coordinates: { latitude: coords.latitude, longitude: coords.longitude },
            });
        }, (error) => {
            setLocating(false);
            setLocationError(error.code === 1
                ? 'Location permission was denied. Enter a city instead.'
                : 'Could not get your location. Enter a city instead.');
        }, { timeout: 8000 });
    }

    const current = result?.selection === selection ? result : null;
    const status = current?.error?.response?.status;

    if (status === 401) {
        return <Navigate to="/login" replace state={{ from: location }} />;
    }

    const completion = current?.error ? completionFromError(current.error) : null;

    return (
        <main className="container py-4 py-md-5 text-start">
            <Link to="/">Back to home</Link>
            <h1 className="h2 mt-3">Nearby cinemas</h1>
            <p>Find cinemas within 10 km of your location or a city.</p>

            <fieldset className="border rounded p-3 mb-4" disabled={locating}>
                <legend className="h5 float-none w-auto px-1">Search location</legend>
                <button type="button" className="btn btn-outline-primary mb-3"
                        onClick={useDeviceLocation}>
                    Use my location
                </button>

                <form onSubmit={submitCity}>
                    <label className="form-label" htmlFor="cinema-city">City</label>
                    <div className="d-flex flex-wrap gap-2">
                        <input id="cinema-city" className="form-control w-auto mw-100"
                               value={city} onChange={(event) => setCity(event.target.value)}
                               placeholder="Beirut, Lebanon" maxLength={120} required />
                        <button type="submit" className="btn btn-primary">Search city</button>
                    </div>
                </form>
            </fieldset>

            {locating && <p role="status">Waiting for device location…</p>}
            {locationError && <p className="alert alert-warning" role="alert">{locationError}</p>}
            {selection && !current && <p role="status">Searching for cinemas…</p>}
            {current?.cityUnavailable && (
                <p className="alert alert-warning" role="alert">
                    Could not look up that city. Try including the country or region.
                </p>
            )}
            {completion && <ProfileCompletionNotice completion={completion} />}
            {current?.error && !completion && (
                <p className="alert alert-danger" role="alert">
                    {status === 429
                        ? 'Too many requests. Wait a minute and try again.'
                        : 'Could not load nearby cinemas. Please try again.'}
                </p>
            )}
            {current?.cinemas?.length === 0 && (
                <p role="status">No cinemas found within 10 km of this location.</p>
            )}
            {current?.cinemas?.length > 0 && (
                <div className="row g-4">
                    <div className="col-lg-7">
                        <CinemaMap {...current.coordinates} cinemas={current.cinemas} />
                    </div>
                    <section className="col-lg-5" aria-labelledby="cinema-results">
                        <h2 id="cinema-results" className="h4">Cinemas near {selection.city || 'you'}</h2>
                        <ul className="list-group">
                            {current.cinemas.map((cinema) => (
                                <li key={cinema.id} className="list-group-item">
                                    <h3 className="h6 mb-1">{cinema.name}</h3>
                                    <p className="mb-1">{cinema.address || 'Address unavailable'}</p>
                                    <a href={directionsUrl(cinema)} target="_blank" rel="noopener noreferrer"
                                       aria-label={`Directions to ${cinema.name} (opens in a new tab)`}>
                                        Directions
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>
            )}
        </main>
    );
}

