import { useCallback, useEffect, useRef, useState } from "react";
import { Link, useParams } from "react-router-dom";
import api from "../services/api";
import {
    assignMovieNightVideo,
    getMovieNight,
    getMovieNightVideo,
    getMovieNightVideoAssets,
} from "../services/movieNights";
import MovieNightError from "../components/MovieNightError";
import MovieNightMessages from "../components/MovieNightMessages";

function clock(value) {
    const seconds = Math.floor(Number.isFinite(value) ? value : 0);
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
}

export default function WatchRoomPage() {
    const { nightId } = useParams();
    const video = useRef(null);
    const [night, setNight] = useState(null);
    const [selected, setSelected] = useState(null);
    const [assets, setAssets] = useState(null);
    const [assetId, setAssetId] = useState("");
    const [time, setTime] = useState(0);
    const [duration, setDuration] = useState(0);
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const load = useCallback(() => {
        return Promise.all([
            getMovieNight(nightId),
            getMovieNightVideo(nightId).catch((e) => {
                if (e.response?.status === 404) return null;

                if (![401, 403, 419].includes(e.response?.status)) {
                    e.videoUnavailable = true;
                }

                throw e;
            }),
        ])
            .then(([loadedNight, loadedVideo]) => {
                setNight(loadedNight);
                setSelected(loadedVideo);

                if (loadedNight.viewer_role === "organizer") {
                    getMovieNightVideoAssets(nightId)
                        .then(setAssets)
                        .catch(() => setAssets([]));
                }
            })
            .catch(setError);
    }, [nightId]);

    useEffect(() => {
        void load();
    }, [load]);
    function updatePlaybackTime(event) {
        setTime(event.currentTarget.currentTime);
    }

    function getPlaybackTime() {
        const currentTime = video.current?.currentTime;
        if (!selected || !Number.isFinite(currentTime)) return null;

        return Math.min(currentTime, selected.video.duration_seconds);
    }

    async function assign(event) {
        event.preventDefault();
        if (!assetId || busy) return;
        setBusy(true);
        try {
            const nextVideo = await assignMovieNightVideo(nightId, assetId);
            if (nextVideo.video.id !== selected?.video.id) {
                setTime(0);
                setDuration(0);
            }
            setSelected(nextVideo);
        } catch (e) {
            setError(e);
        } finally {
            setBusy(false);
        }
    }

    if (error) {
        const videoError =
            error.videoUnavailable ||
            error.message === "Video playback failed.";

        return (
            <main className="container py-4">
                <MovieNightError
                    error={error}
                    message={
                        videoError
                            ? "The selected video is currently unavailable. Please contact the movie night organizer or try again later."
                            : "Could not load this watch room."
                    }
                    onRetry={() => {
                        setError(null);
                        void load();
                    }}
                />
            </main>
        );
    }

    if (!night)
        return (
            <main className="container py-4">
                <p role="status">Loading watch room…</p>
            </main>
        );
    const src = selected
        ? new URL(selected.video.stream_url, api.defaults.baseURL).href
        : null;
    return (
        <main className="container py-4 py-md-5 text-start">
            <Link to={`/movie-nights/${nightId}`}>Back to movie night</Link>
            <h1 className="h2 my-3">Watch Room: {night.title}</h1>
            {night.viewer_role === "organizer" && assets && (
                <form onSubmit={assign} className="card mb-3">
                    <div className="card-body">
                        <label htmlFor="video-asset" className="form-label">
                            Demo video asset
                        </label>
                        <div className="d-flex flex-column flex-sm-row gap-2">
                            <select
                                id="video-asset"
                                className="form-select"
                                value={assetId}
                                onChange={(e) => setAssetId(e.target.value)}
                            >
                                <option value="">
                                    Choose a licensed demo clip
                                </option>
                                {assets.map((asset) => (
                                    <option key={asset.id} value={asset.id}>
                                        {asset.title} (
                                        {clock(asset.duration_seconds)})
                                    </option>
                                ))}
                            </select>
                            <button
                                className="btn btn-primary text-nowrap"
                                disabled={!assetId || busy}
                            >
                                {busy ? "Assigning…" : "Assign clip"}
                            </button>
                        </div>
                        {assets.length === 0 && (
                            <p className="small text-body-secondary mt-2 mb-0">
                                No demo video assets are available.
                            </p>
                        )}
                    </div>
                </form>
            )}
            {!selected && (
                <p role="status">
                    No video clip has been selected for this movie night yet.
                </p>
            )}
            {selected && (
                <section className="card">
                    <div className="card-body">
                        <h2 className="h5">{selected.video.title}</h2>
                        <video
                            key={`${nightId}:${selected.video.id}`}
                            ref={video}
                            className="w-100"
                            controls
                            crossOrigin="use-credentials"
                            src={src}
                            onTimeUpdate={updatePlaybackTime}
                            onSeeking={updatePlaybackTime}
                            onSeeked={updatePlaybackTime}
                            onLoadedMetadata={(e) =>
                                setDuration(e.currentTarget.duration)
                            }
                            onError={() =>
                                setError(new Error("Video playback failed."))
                            }
                        >
                            Your browser does not support HTML5 video.
                        </video>
                        <p
                            className="small text-body-secondary mt-2 mb-0"
                            aria-live="polite"
                        >
                            Playback: {clock(time)} /{" "}
                            {clock(duration || selected.video.duration_seconds)}
                        </p>
                    </div>
                </section>
            )}
            {String(night.id) === nightId && (
                <MovieNightMessages
                    key={nightId}
                    nightId={nightId}
                    canSend={["scheduled", "finalized"].includes(night.status)}
                    playbackTime={selected ? time : null}
                    getPlaybackTime={getPlaybackTime}
                />
            )}
        </main>
    );
}
