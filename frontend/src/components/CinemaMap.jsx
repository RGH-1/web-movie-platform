import { useEffect, useRef } from 'react';
import L from 'leaflet';
import markerImage from 'leaflet/dist/images/marker-icon.png';
import markerRetinaImage from 'leaflet/dist/images/marker-icon-2x.png';
import 'leaflet/dist/leaflet.css';

const cinemaIcon = L.icon({
    iconUrl: markerImage,
    iconRetinaUrl: markerRetinaImage,
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [0, -38],
});

export default function CinemaMap({ latitude, longitude, cinemas }) {
    const container = useRef(null);

    useEffect(() => {
        const center = [latitude, longitude];
        const map = L.map(container.current).setView(center, 12);

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: [
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
                'Cinema data powered by <a href="https://www.geoapify.com/">Geoapify</a>',
            ].join(' | '),
        }).addTo(map);

        const bounds = L.latLngBounds([center]);

        for (const cinema of cinemas) {
            const position = [cinema.latitude, cinema.longitude];
            const label = document.createElement('span');
            label.textContent = cinema.name;

            L.marker(position, {
                icon: cinemaIcon,
                alt: cinema.name,
                title: cinema.name,
            }).addTo(map).bindPopup(label);

            bounds.extend(position);
        }

        if (cinemas.length > 0) {
            map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 });
        }

        return () => map.remove();
    }, [latitude, longitude, cinemas]);

    return (
        <div
            ref={container}
            role="region"
            aria-label="Nearby cinemas map"
            className="rounded border"
            style={{ height: 'clamp(280px, 50vh, 480px)' }}
        />
    );
}