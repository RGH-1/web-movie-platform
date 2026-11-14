import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";

import MyMoviesPage from "../../src/pages/MyMoviesPage.jsx";
import { useAuth } from "../../src/hooks/useAuth.js";
import {
    getMyMovies,
    updateMovieStatus,
} from "../../src/services/movieStatus.js";

vi.mock("../../src/hooks/useAuth.js", () => ({
    useAuth: vi.fn(),
}));

vi.mock("../../src/services/movieStatus.js", () => ({
    getMyMovies: vi.fn(),
    updateMovieStatus: vi.fn(),
}));

function makeEntry(id, overrides = {}) {
    const { movie: movieOverrides = {}, ...statusOverrides } = overrides;

    return {
        movie_id: String(id),
        in_watchlist: true,
        is_watched: false,
        watched_at: null,
        ...statusOverrides,
        movie: {
            id: String(id),
            tmdb_id: 1000 + Number(id),
            title: `Movie ${id}`,
            overview: "A test movie.",
            poster_url: null,
            release_date: "2020-01-01",
            ...movieOverrides,
        },
    };
}

function makeCollection(filter, entries = [], options = {}) {
    return {
        data: entries,
        meta: {
            filter,
            current_page: options.page ?? 1,
            last_page: options.lastPage ?? 1,
            per_page: 12,
            total: options.total ?? entries.length,
        },
        links: {
            next: null,
            previous: null,
        },
    };
}

function renderPage() {
    return render(
        <MemoryRouter>
            <MyMoviesPage />
        </MemoryRouter>,
    );
}

function deferred() {
    let resolve;
    let reject;

    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

describe("MyMoviesPage", () => {
    beforeEach(() => {
        vi.resetAllMocks();

        useAuth.mockReturnValue({
            user: { id: "5", name: "Test User" },
        });

        getMyMovies.mockResolvedValue(makeCollection("watchlist"));
    });

    it("shows loading while retrieving the watchlist", async () => {
        const request = deferred();

        getMyMovies.mockReturnValue(request.promise);

        renderPage();

        expect(
            screen.getByText("Loading your watchlist..."),
        ).toBeInTheDocument();

        await act(async () => {
            request.resolve(makeCollection("watchlist"));
        });

        expect(
            screen.getByRole("heading", {
                name: "Your watchlist is empty",
            }),
        ).toBeInTheDocument();

        expect(getMyMovies).toHaveBeenCalledWith("watchlist", 1, 12);
    });

    it("shows an empty watchlist with a discovery link", async () => {
        renderPage();

        expect(
            await screen.findByRole("heading", {
                name: "Your watchlist is empty",
            }),
        ).toBeInTheDocument();

        const links = screen.getAllByRole("link", {
            name: "Discover Movies",
        });

        expect(links).toHaveLength(2);

        for (const link of links) {
            expect(link).toHaveAttribute("href", "/movies");
        }
    });

    it("switches between watchlist and watched history", async () => {
        const user = userEvent.setup();

        getMyMovies.mockImplementation((filter) => {
            if (filter === "watchlist") {
                return Promise.resolve(
                    makeCollection("watchlist", [makeEntry(1)]),
                );
            }

            return Promise.resolve(
                makeCollection("watched", [
                    makeEntry(2, {
                        in_watchlist: false,
                        is_watched: true,
                        watched_at: "2026-11-10T12:00:00Z",
                    }),
                ]),
            );
        });

        renderPage();

        expect(
            await screen.findByRole("heading", {
                name: "Movie 1",
            }),
        ).toBeInTheDocument();

        await user.click(
            screen.getByRole("tab", {
                name: "Watched History",
            }),
        );

        expect(
            await screen.findByRole("heading", {
                name: "Movie 2",
            }),
        ).toBeInTheDocument();

        expect(screen.getByText(/Watched:/)).toBeInTheDocument();

        expect(
            screen.getByRole("button", {
                name: "Mark as Unwatched",
            }),
        ).toBeInTheDocument();

        expect(getMyMovies).toHaveBeenCalledWith("watched", 1, 12);
    });

    it("uses the TMDB ID for movie details navigation", async () => {
        getMyMovies.mockResolvedValue(
            makeCollection("watchlist", [
                makeEntry(15, {
                    movie: {
                        title: "Interstellar",
                        tmdb_id: 157336,
                    },
                }),
            ]),
        );

        renderPage();

        const detailsLink = await screen.findByRole("link", {
            name: "View details for Interstellar",
        });

        expect(detailsLink).toHaveAttribute("href", "/movies/157336");
    });

    it("loads the requested pagination page", async () => {
        const user = userEvent.setup();

        getMyMovies.mockImplementation((filter, page) => {
            return Promise.resolve(
                makeCollection(filter, [makeEntry(page)], {
                    page,
                    lastPage: 2,
                    total: 2,
                }),
            );
        });

        renderPage();

        expect(
            await screen.findByRole("heading", {
                name: "Movie 1",
            }),
        ).toBeInTheDocument();

        await user.click(
            screen.getByRole("button", {
                name: "Next",
            }),
        );

        expect(
            await screen.findByRole("heading", {
                name: "Movie 2",
            }),
        ).toBeInTheDocument();

        expect(getMyMovies).toHaveBeenCalledWith("watchlist", 2, 12);

        expect(screen.getByText("Page 2 of 2")).toBeInTheDocument();
    });

    it("removes a movie from the watchlist and refreshes", async () => {
        const user = userEvent.setup();

        getMyMovies
            .mockResolvedValueOnce(makeCollection("watchlist", [makeEntry(15)]))
            .mockResolvedValueOnce(makeCollection("watchlist"));

        updateMovieStatus.mockResolvedValue({
            movie_id: "15",
            in_watchlist: false,
            is_watched: false,
            watched_at: null,
        });

        renderPage();

        await user.click(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        );

        expect(updateMovieStatus).toHaveBeenCalledWith("15", {
            in_watchlist: false,
        });

        expect(
            await screen.findByRole("heading", {
                name: "Your watchlist is empty",
            }),
        ).toBeInTheDocument();

        expect(getMyMovies).toHaveBeenCalledTimes(2);
    });

    it("marks a watchlisted movie as watched independently", async () => {
        const user = userEvent.setup();

        const watchedEntry = makeEntry(15, {
            in_watchlist: true,
            is_watched: true,
            watched_at: "2026-11-14T12:00:00Z",
        });

        getMyMovies
            .mockResolvedValueOnce(makeCollection("watchlist", [makeEntry(15)]))
            .mockResolvedValueOnce(makeCollection("watchlist", [watchedEntry]));

        updateMovieStatus.mockResolvedValue({
            movie_id: "15",
            in_watchlist: true,
            is_watched: true,
            watched_at: watchedEntry.watched_at,
        });

        renderPage();

        await user.click(
            await screen.findByRole("button", {
                name: "Mark as Watched",
            }),
        );

        expect(updateMovieStatus).toHaveBeenCalledWith("15", {
            is_watched: true,
        });

        expect(
            await screen.findByRole("button", {
                name: "Mark as Unwatched",
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByRole("button", {
                name: "Remove from Watchlist",
            }),
        ).toBeInTheDocument();
    });

    it("removes unwatched movies from watched history", async () => {
        const user = userEvent.setup();

        const watchedEntry = makeEntry(15, {
            in_watchlist: false,
            is_watched: true,
            watched_at: "2026-11-14T12:00:00Z",
        });

        getMyMovies
            .mockResolvedValueOnce(makeCollection("watchlist"))
            .mockResolvedValueOnce(makeCollection("watched", [watchedEntry]))
            .mockResolvedValueOnce(makeCollection("watched"));

        updateMovieStatus.mockResolvedValue({
            movie_id: "15",
            in_watchlist: false,
            is_watched: false,
            watched_at: null,
        });

        renderPage();

        await user.click(
            screen.getByRole("tab", {
                name: "Watched History",
            }),
        );

        await user.click(
            await screen.findByRole("button", {
                name: "Mark as Unwatched",
            }),
        );

        expect(updateMovieStatus).toHaveBeenCalledWith("15", {
            is_watched: false,
        });

        expect(
            await screen.findByRole("heading", {
                name: "No watched movies yet",
            }),
        ).toBeInTheDocument();
    });

    it("shows an error and allows retrying a failed collection request", async () => {
        const user = userEvent.setup();

        getMyMovies
            .mockRejectedValueOnce(new Error("Network unavailable"))
            .mockResolvedValueOnce(
                makeCollection("watchlist", [makeEntry(15)]),
            );

        renderPage();

        expect(await screen.findByRole("alert")).toBeInTheDocument();

        await user.click(
            screen.getByRole("button", {
                name: "Try again",
            }),
        );

        expect(
            await screen.findByRole("heading", {
                name: "Movie 15",
            }),
        ).toBeInTheDocument();

        expect(getMyMovies).toHaveBeenCalledTimes(2);
    });

    it("shows the profile completion link on a 403 response", async () => {
        getMyMovies.mockRejectedValue({
            response: { status: 403 },
        });

        renderPage();

        const link = await screen.findByRole("link", {
            name: "Complete profile",
        });

        expect(link).toHaveAttribute("href", "/profile?edit=1");
    });

    it("preserves the displayed collection when PATCH fails", async () => {
        const user = userEvent.setup();

        getMyMovies.mockResolvedValue(
            makeCollection("watchlist", [makeEntry(15)]),
        );

        updateMovieStatus.mockRejectedValue(new Error("Network failure"));

        renderPage();

        await user.click(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        );

        expect(await screen.findByRole("alert")).toBeInTheDocument();

        expect(
            screen.getByRole("heading", {
                name: "Movie 15",
            }),
        ).toBeInTheDocument();

        expect(getMyMovies).toHaveBeenCalledTimes(1);
    });

    it("disables status buttons during a pending update", async () => {
        const user = userEvent.setup();
        const request = deferred();

        getMyMovies.mockResolvedValue(
            makeCollection("watchlist", [makeEntry(15)]),
        );

        updateMovieStatus.mockReturnValue(request.promise);

        renderPage();

        await user.click(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        );

        expect(
            screen.getByRole("button", {
                name: "Remove from Watchlist",
            }),
        ).toBeDisabled();

        expect(
            screen.getByRole("button", {
                name: "Mark as Watched",
            }),
        ).toBeDisabled();

        expect(updateMovieStatus).toHaveBeenCalledTimes(1);

        await act(async () => {
            request.resolve({
                in_watchlist: true,
                is_watched: false,
            });
        });

        await waitFor(() => {
            expect(
                screen.getByRole("button", {
                    name: "Remove from Watchlist",
                }),
            ).toBeEnabled();
        });
    });

    it("ignores an outdated collection response after switching tabs", async () => {
        const user = userEvent.setup();
        const oldRequest = deferred();

        getMyMovies.mockImplementation((filter) => {
            if (filter === "watchlist") {
                return oldRequest.promise;
            }

            return Promise.resolve(
                makeCollection("watched", [
                    makeEntry(20, {
                        in_watchlist: false,
                        is_watched: true,
                        watched_at: "2026-11-14T12:00:00Z",
                    }),
                ]),
            );
        });

        renderPage();

        await user.click(
            screen.getByRole("tab", {
                name: "Watched History",
            }),
        );

        expect(
            await screen.findByRole("heading", {
                name: "Movie 20",
            }),
        ).toBeInTheDocument();

        await act(async () => {
            oldRequest.resolve(makeCollection("watchlist", [makeEntry(15)]));
        });

        expect(
            screen.getByRole("heading", {
                name: "Movie 20",
            }),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole("heading", {
                name: "Movie 15",
            }),
        ).not.toBeInTheDocument();
    });

    it("returns to page 1 after removing the last movie on page 2", async () => {
        const user = userEvent.setup();
        let removed = false;

        getMyMovies.mockImplementation((filter, page) => {
            if (page === 2) {
                return Promise.resolve(
                    makeCollection("watchlist", [makeEntry(2)], {
                        page: 2,
                        lastPage: 2,
                        total: 2,
                    }),
                );
            }

            return Promise.resolve(
                makeCollection("watchlist", [makeEntry(1)], {
                    page: 1,
                    lastPage: removed ? 1 : 2,
                    total: removed ? 1 : 2,
                }),
            );
        });

        updateMovieStatus.mockImplementation(async () => {
            removed = true;

            return {
                movie_id: "2",
                in_watchlist: false,
                is_watched: false,
                watched_at: null,
            };
        });

        renderPage();

        await screen.findByRole("heading", { name: "Movie 1" });

        await user.click(screen.getByRole("button", { name: "Next" }));

        await screen.findByRole("heading", { name: "Movie 2" });

        await user.click(
            screen.getByRole("button", {
                name: "Remove from Watchlist",
            }),
        );

        await waitFor(() => {
            expect(getMyMovies).toHaveBeenLastCalledWith("watchlist", 1, 12);
        });

        expect(
            await screen.findByRole("heading", { name: "Movie 1" }),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole("heading", { name: "Movie 2" }),
        ).not.toBeInTheDocument();
    });

    it("ignores a completed PATCH after switching tabs", async () => {
        const user = userEvent.setup();
        const pendingUpdate = deferred();

        getMyMovies.mockImplementation((filter) => {
            if (filter === "watchlist") {
                return Promise.resolve(
                    makeCollection("watchlist", [makeEntry(15)]),
                );
            }

            return Promise.resolve(
                makeCollection("watched", [
                    makeEntry(20, {
                        in_watchlist: false,
                        is_watched: true,
                        watched_at: "2026-11-14T12:00:00Z",
                    }),
                ]),
            );
        });

        updateMovieStatus.mockReturnValue(pendingUpdate.promise);

        renderPage();

        await user.click(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        );

        await user.click(
            screen.getByRole("tab", {
                name: "Watched History",
            }),
        );

        expect(
            await screen.findByRole("heading", {
                name: "Movie 20",
            }),
        ).toBeInTheDocument();

        await act(async () => {
            pendingUpdate.resolve({
                movie_id: "15",
                in_watchlist: false,
                is_watched: false,
                watched_at: null,
            });
        });

        expect(
            screen.getByRole("heading", {
                name: "Movie 20",
            }),
        ).toBeInTheDocument();

        expect(getMyMovies).toHaveBeenCalledTimes(2);
    });
});
