import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";

import MovieStatusActions from "../../src/components/MovieStatusActions.jsx";
import { useAuth } from "../../src/hooks/useAuth.js";
import {
    getMovieStatus,
    updateMovieStatus,
} from "../../src/services/movieStatus.js";

vi.mock("../../src/hooks/useAuth.js", () => ({
    useAuth: vi.fn(),
}));

vi.mock("../../src/services/movieStatus.js", () => ({
    getMovieStatus: vi.fn(),
    updateMovieStatus: vi.fn(),
}));

function makeStatus(overrides = {}) {
    return {
        movie_id: "15",
        in_watchlist: false,
        is_watched: false,
        watched_at: null,
        ...overrides,
    };
}

function renderMovie(movie = { local_movie_id: "15" }) {
    return render(
        <MemoryRouter>
            <MovieStatusActions movie={movie} />
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

describe("MovieStatusActions", () => {
    beforeEach(() => {
        vi.resetAllMocks();

        useAuth.mockReturnValue({
            user: { id: "5", name: "Test User" },
            loading: false,
            profileCompletion: { complete: true },
        });

        getMovieStatus.mockResolvedValue(makeStatus());
    });

    it("shows authentication loading", () => {
        useAuth.mockReturnValue({
            user: null,
            loading: true,
            profileCompletion: null,
        });

        renderMovie();

        expect(screen.getByText("Checking authentication...")).toBeTruthy();

        expect(getMovieStatus).not.toHaveBeenCalled();
    });

    it("shows a login link for guests", () => {
        useAuth.mockReturnValue({
            user: null,
            loading: false,
            profileCompletion: null,
        });

        renderMovie();

        const link = screen.getByRole("link", {
            name: /sign in to manage your watchlist/i,
        });

        expect(link.getAttribute("href")).toBe("/login");
        expect(getMovieStatus).not.toHaveBeenCalled();
    });

    it("asks users with incomplete profiles to complete them", () => {
        useAuth.mockReturnValue({
            user: { id: "5" },
            loading: false,
            profileCompletion: { complete: false },
        });

        renderMovie();

        const link = screen.getByRole("link", {
            name: /complete your profile/i,
        });

        expect(link.getAttribute("href")).toBe("/profile?edit=1");
        expect(getMovieStatus).not.toHaveBeenCalled();
    });

    it("does not request status without a canonical movie ID", () => {
        renderMovie({ tmdb_id: 157336 });

        expect(
            screen.getByText(/watchlist and watched controls are unavailable/i),
        ).toBeTruthy();

        expect(getMovieStatus).not.toHaveBeenCalled();
    });

    it("shows a loading message while fetching saved status", async () => {
        const request = deferred();

        getMovieStatus.mockReturnValue(request.promise);

        renderMovie();

        expect(screen.getByText("Loading your movie status...")).toBeTruthy();

        await act(async () => {
            request.resolve(makeStatus());
        });

        expect(
            await screen.findByRole("button", {
                name: "Add to Watchlist",
            }),
        ).toBeTruthy();

        expect(getMovieStatus).toHaveBeenCalledTimes(1);
        expect(getMovieStatus).toHaveBeenCalledWith(
            "15",
            expect.objectContaining({
                signal: expect.anything(),
            }),
        );
    });

    it("displays previously saved statuses correctly", async () => {
        getMovieStatus.mockResolvedValue(
            makeStatus({
                in_watchlist: true,
                is_watched: true,
                watched_at: "2026-11-14T10:00:00Z",
            }),
        );

        renderMovie();

        const watchlistButton = await screen.findByRole("button", {
            name: "Remove from Watchlist",
        });

        const watchedButton = screen.getByRole("button", {
            name: "Mark as Unwatched",
        });

        expect(watchlistButton.getAttribute("aria-pressed")).toBe("true");
        expect(watchedButton.getAttribute("aria-pressed")).toBe("true");
    });

    it("adds and removes a movie from the watchlist", async () => {
        const user = userEvent.setup();

        updateMovieStatus
            .mockResolvedValueOnce(makeStatus({ in_watchlist: true }))
            .mockResolvedValueOnce(makeStatus());

        renderMovie();

        await user.click(
            await screen.findByRole("button", {
                name: "Add to Watchlist",
            }),
        );

        expect(updateMovieStatus).toHaveBeenCalledWith("15", {
            in_watchlist: true,
        });

        await user.click(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        );

        expect(updateMovieStatus).toHaveBeenLastCalledWith("15", {
            in_watchlist: false,
        });

        expect(
            await screen.findByRole("button", {
                name: "Add to Watchlist",
            }),
        ).toBeTruthy();
    });

    it("marks a movie watched and unwatched", async () => {
        const user = userEvent.setup();

        updateMovieStatus
            .mockResolvedValueOnce(
                makeStatus({
                    is_watched: true,
                    watched_at: "2026-11-14T10:00:00Z",
                }),
            )
            .mockResolvedValueOnce(makeStatus());

        renderMovie();

        await user.click(
            await screen.findByRole("button", {
                name: "Mark as Watched",
            }),
        );

        expect(updateMovieStatus).toHaveBeenCalledWith("15", {
            is_watched: true,
        });

        await user.click(
            await screen.findByRole("button", {
                name: "Mark as Unwatched",
            }),
        );

        expect(updateMovieStatus).toHaveBeenLastCalledWith("15", {
            is_watched: false,
        });

        expect(
            await screen.findByRole("button", {
                name: "Mark as Watched",
            }),
        ).toBeTruthy();
    });

    it("changes watchlist without changing watched status", async () => {
        const user = userEvent.setup();

        getMovieStatus.mockResolvedValue(
            makeStatus({
                is_watched: true,
                watched_at: "2026-11-14T10:00:00Z",
            }),
        );

        updateMovieStatus.mockResolvedValue(
            makeStatus({
                in_watchlist: true,
                is_watched: true,
                watched_at: "2026-11-14T10:00:00Z",
            }),
        );

        renderMovie();

        await user.click(
            await screen.findByRole("button", {
                name: "Add to Watchlist",
            }),
        );

        expect(updateMovieStatus).toHaveBeenCalledWith("15", {
            in_watchlist: true,
        });

        expect(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        ).toBeTruthy();

        expect(
            screen.getByRole("button", {
                name: "Mark as Unwatched",
            }),
        ).toBeTruthy();
    });

    it("prevents duplicate requests while saving", async () => {
        const user = userEvent.setup();
        const request = deferred();

        updateMovieStatus.mockReturnValue(request.promise);

        renderMovie();

        const button = await screen.findByRole("button", {
            name: "Add to Watchlist",
        });

        await user.click(button);

        expect(updateMovieStatus).toHaveBeenCalledTimes(1);
        expect(button.disabled).toBe(true);

        expect(
            screen.getByRole("button", {
                name: "Mark as Watched",
            }).disabled,
        ).toBe(true);

        await user.click(button);

        expect(updateMovieStatus).toHaveBeenCalledTimes(1);

        await act(async () => {
            request.resolve(makeStatus({ in_watchlist: true }));
        });

        await waitFor(() => {
            expect(
                screen.getByRole("button", {
                    name: "Remove from Watchlist",
                }).disabled,
            ).toBe(false);
        });
    });

    it("preserves confirmed status when an update fails", async () => {
        const user = userEvent.setup();

        updateMovieStatus.mockRejectedValue(new Error("Network failure"));

        renderMovie();

        await user.click(
            await screen.findByRole("button", {
                name: "Add to Watchlist",
            }),
        );

        expect(await screen.findByRole("alert")).toBeTruthy();

        expect(
            screen.getByRole("button", {
                name: "Add to Watchlist",
            }),
        ).toBeTruthy();

        expect(
            screen.getByRole("button", {
                name: "Mark as Watched",
            }),
        ).toBeTruthy();
    });

    it("allows retrying after an initial GET failure", async () => {
        const user = userEvent.setup();

        getMovieStatus
            .mockRejectedValueOnce(new Error("Network failure"))
            .mockResolvedValueOnce(makeStatus());

        renderMovie();

        expect(await screen.findByRole("alert")).toBeTruthy();

        await user.click(
            screen.getByRole("button", {
                name: "Try again",
            }),
        );

        expect(
            await screen.findByRole("button", {
                name: "Add to Watchlist",
            }),
        ).toBeTruthy();

        expect(getMovieStatus).toHaveBeenCalledTimes(2);
    });

    it("ignores outdated GET responses when switching movies", async () => {
        const firstRequest = deferred();

        getMovieStatus.mockImplementation((movieId) => {
            if (movieId === "15") {
                return firstRequest.promise;
            }

            return Promise.resolve(
                makeStatus({
                    movie_id: "16",
                    in_watchlist: true,
                }),
            );
        });

        const view = renderMovie({ local_movie_id: "15" });

        await waitFor(() => {
            expect(getMovieStatus).toHaveBeenCalledWith(
                "15",
                expect.anything(),
            );
        });

        view.rerender(
            <MemoryRouter>
                <MovieStatusActions movie={{ local_movie_id: "16" }} />
            </MemoryRouter>,
        );

        expect(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        ).toBeTruthy();

        await act(async () => {
            firstRequest.resolve(
                makeStatus({
                    movie_id: "15",
                    in_watchlist: false,
                }),
            );
        });

        expect(
            screen.getByRole("button", {
                name: "Remove from Watchlist",
            }),
        ).toBeTruthy();

        expect(
            screen.queryByRole("button", {
                name: "Add to Watchlist",
            }),
        ).toBeNull();
    });

    it("loads persisted statuses again when the component remounts", async () => {
        getMovieStatus
            .mockResolvedValueOnce(makeStatus())
            .mockResolvedValueOnce(makeStatus({ in_watchlist: true }));

        const firstView = renderMovie();

        expect(
            await screen.findByRole("button", {
                name: "Add to Watchlist",
            }),
        ).toBeTruthy();

        firstView.unmount();

        renderMovie();

        expect(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        ).toBeTruthy();

        expect(getMovieStatus).toHaveBeenCalledTimes(2);
    });

    it("ignores an old PATCH response after switching movies", async () => {
        const user = userEvent.setup();
        const pendingUpdate = deferred();

        getMovieStatus.mockImplementation((movieId) => {
            return Promise.resolve(
                makeStatus({
                    movie_id: movieId,
                    in_watchlist: movieId === "16",
                }),
            );
        });

        updateMovieStatus.mockReturnValue(pendingUpdate.promise);

        const view = renderMovie({ local_movie_id: "15" });

        await user.click(
            await screen.findByRole("button", {
                name: "Add to Watchlist",
            }),
        );

        expect(updateMovieStatus).toHaveBeenCalledWith("15", {
            in_watchlist: true,
        });

        view.rerender(
            <MemoryRouter>
                <MovieStatusActions movie={{ local_movie_id: "16" }} />
            </MemoryRouter>,
        );

        expect(
            await screen.findByRole("button", {
                name: "Remove from Watchlist",
            }),
        ).toBeTruthy();

        await act(async () => {
            pendingUpdate.resolve(
                makeStatus({
                    movie_id: "15",
                    in_watchlist: false,
                    is_watched: true,
                    watched_at: "2026-11-14T15:00:00Z",
                }),
            );
        });

        expect(
            screen.getByRole("button", {
                name: "Remove from Watchlist",
            }),
        ).toBeTruthy();

        expect(
            screen.getByRole("button", {
                name: "Mark as Watched",
            }),
        ).toBeTruthy();

        expect(
            screen.queryByRole("button", {
                name: "Mark as Unwatched",
            }),
        ).toBeNull();
    });
});
