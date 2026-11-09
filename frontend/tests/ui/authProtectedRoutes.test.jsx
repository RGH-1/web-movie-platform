import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";

import ProtectedRoute from "../../src/components/ProtectedRoute";
import { useAuth } from "../../src/hooks/useAuth";

vi.mock("../../src/hooks/useAuth", () => ({
    useAuth: vi.fn(),
}));

// Display the login destination and the original URL.
function LoginDestination() {
    const location = useLocation();
    const from = location.state?.from;

    const originalUrl = from
        ? `${from.pathname}${from.search}${from.hash}`
        : "No original URL";

    return (
        <main>
            <h1>Login page</h1>
            <p data-testid="original-url">{originalUrl}</p>
        </main>
    );
}

// A small test application with protected routes.
function TestRoutes({ initialUrl = "/profile" }) {
    return (
        <MemoryRouter initialEntries={[initialUrl]}>
            <Routes>
                <Route path="/login" element={<LoginDestination />} />

                <Route element={<ProtectedRoute />}>
                    <Route path="/profile" element={<h1>Profile page</h1>} />

                    <Route
                        path="/movies/:tmdbId"
                        element={<h1>Movie details page</h1>}
                    />
                </Route>
            </Routes>
        </MemoryRouter>
    );
}

afterEach(() => {
    cleanup();
    vi.resetAllMocks();
});

describe("Protected routes (AB#29)", () => {
    // TEST 1: Authentication is still loading.
    it("waits for authentication before allowing navigation", () => {
        useAuth.mockReturnValue({
            isAuthenticated: false,
            loading: true,
        });

        render(<TestRoutes />);

        expect(
            screen.getByText("Checking authentication..."),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole("heading", { name: "Login page" }),
        ).not.toBeInTheDocument();

        expect(
            screen.queryByRole("heading", { name: "Profile page" }),
        ).not.toBeInTheDocument();
    });

    // TEST 2: Logged-out user tries to open a protected URL.
    it("redirects logged-out users and preserves their destination", async () => {
        useAuth.mockReturnValue({
            isAuthenticated: false,
            loading: false,
        });

        render(<TestRoutes initialUrl="/movies/157336?from=search#cast" />);

        expect(
            await screen.findByRole("heading", {
                name: "Login page",
            }),
        ).toBeInTheDocument();

        expect(screen.getByTestId("original-url")).toHaveTextContent(
            "/movies/157336?from=search#cast",
        );

        expect(
            screen.queryByRole("heading", {
                name: "Movie details page",
            }),
        ).not.toBeInTheDocument();
    });

    // TEST 3: Authenticated user opens a protected page.
    it("allows authenticated users to view protected content", () => {
        useAuth.mockReturnValue({
            isAuthenticated: true,
            loading: false,
        });

        render(<TestRoutes initialUrl="/profile" />);

        expect(
            screen.getByRole("heading", {
                name: "Profile page",
            }),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole("heading", {
                name: "Login page",
            }),
        ).not.toBeInTheDocument();
    });

    // TEST 4: Protected content disappears after logout.
    it("redirects when the user becomes unauthenticated", async () => {
        let authenticated = true;

        useAuth.mockImplementation(() => ({
            isAuthenticated: authenticated,
            loading: false,
        }));

        const { rerender } = render(<TestRoutes initialUrl="/profile" />);

        expect(
            screen.getByRole("heading", {
                name: "Profile page",
            }),
        ).toBeInTheDocument();

        // Simulate the authentication state changing.
        authenticated = false;

        rerender(<TestRoutes initialUrl="/profile" />);

        expect(
            await screen.findByRole("heading", {
                name: "Login page",
            }),
        ).toBeInTheDocument();

        expect(
            screen.queryByRole("heading", {
                name: "Profile page",
            }),
        ).not.toBeInTheDocument();
    });
});
