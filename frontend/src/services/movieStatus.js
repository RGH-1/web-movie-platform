
import api from "./api.js";

function validateMovieId(movieId) {
    const id = String(movieId ?? "");

    if (!/^[1-9]\d*$/.test(id)) {
        throw new Error("Invalid canonical movie ID.");
    }

    return id;
}

export async function getMovieStatus(movieId, { signal } = {}) {
    const id = validateMovieId(movieId);

    const response = await api.get(
        `/v1/me/movies/${id}/status`,
        { signal }
    );

    return response.data.data;
}

export async function updateMovieStatus(movieId, changes) {
    const id = validateMovieId(movieId);

    const fields = Object.keys(changes ?? {});

    if (
        fields.length !== 1 ||
        !["in_watchlist", "is_watched"].includes(fields[0]) ||
        typeof changes[fields[0]] !== "boolean"
    ) {
        throw new Error("Invalid movie status update.");
    }

    const response = await api.patch(
        `/v1/me/movies/${id}/status`,
        changes
    );

    return response.data.data;
}
