import { useState } from "react";
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AuthProvider } from "../../src/context/AuthContext";
import { useAuth } from "../../src/hooks/useAuth";
import { getCurrentUser, logoutUser } from "../../src/services/auth";
import api from "../../src/services/api";

// Mock Laravel authentication requests.
vi.mock("../../src/services/auth", () => ({
    getCurrentUser: vi.fn(),
    loginUser: vi.fn(),
    registerUser: vi.fn(),
    logoutUser: vi.fn(),
}));

// Mock Axios while keeping its interceptor registration testable.
vi.mock("../../src/services/api", () => ({
    default: {
        get: vi.fn(),
        interceptors: {
            response: {
                use: vi.fn(),
                eject: vi.fn(),
            },
        },
    },
}));

// This component displays the REAL AuthContext state.
function AuthStatus() {
    const { user, loading, isAuthenticated, signOut } = useAuth();
    const [logoutError, setLogoutError] = useState("");

    async function handleLogout() {
        try {
            await signOut();
        } catch {
            setLogoutError("Logout failed");
        }
    }

    return (
        <div>
            <p role="status">
                {loading
                    ? "Checking authentication..."
                    : isAuthenticated
                      ? `Signed in: ${user.name}`
                      : "Signed out"}
            </p>

            <button type="button" onClick={handleLogout}>
                Log out
            </button>

            {logoutError && <p role="alert">{logoutError}</p>}
        </div>
    );
}

function renderAuth() {
    return render(
        <AuthProvider>
            <AuthStatus />
        </AuthProvider>,
    );
}

const testUser = {
    id: 1,
    name: "Test User",
    email: "test@example.com",
};

beforeEach(() => {
    vi.resetAllMocks();

    // Default: no existing authenticated session.
    getCurrentUser.mockRejectedValue({
        response: { status: 401 },
    });

    api.get.mockResolvedValue({
        data: { meta: null },
    });

    logoutUser.mockResolvedValue(undefined);
});

afterEach(() => {
    cleanup();
});

describe("Authentication context (AB#29)", () => {
    it("restores an existing authenticated session", async () => {
        getCurrentUser.mockResolvedValue(testUser);

        renderAuth();

        expect(
            await screen.findByText("Signed in: Test User"),
        ).toBeInTheDocument();

        expect(getCurrentUser).toHaveBeenCalledTimes(1);
    });

    it("treats an expired startup session as logged out", async () => {
        renderAuth();

        expect(await screen.findByText("Signed out")).toBeInTheDocument();

        expect(getCurrentUser).toHaveBeenCalledTimes(1);
    });

    it("clears authentication after successful logout", async () => {
        getCurrentUser.mockResolvedValue(testUser);

        renderAuth();

        await screen.findByText("Signed in: Test User");

        fireEvent.click(screen.getByRole("button", { name: "Log out" }));

        expect(await screen.findByText("Signed out")).toBeInTheDocument();

        expect(logoutUser).toHaveBeenCalledTimes(1);
    });

    it("keeps the user authenticated when logout fails", async () => {
        getCurrentUser.mockResolvedValue(testUser);

        logoutUser.mockRejectedValue({
            response: { status: 500 },
        });

        renderAuth();

        await screen.findByText("Signed in: Test User");

        fireEvent.click(screen.getByRole("button", { name: "Log out" }));

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Logout failed",
        );

        expect(screen.getByText("Signed in: Test User")).toBeInTheDocument();
    });

    it("clears authentication when an API returns HTTP 401", async () => {
        getCurrentUser.mockResolvedValue(testUser);

        renderAuth();

        await screen.findByText("Signed in: Test User");

        // AuthProvider registers a response interceptor.
        const onRejected = api.interceptors.response.use.mock.calls[0][1];

        const unauthorizedError = {
            response: { status: 401 },
            config: { url: "/v1/movies/157336" },
        };

        await act(async () => {
            await expect(onRejected(unauthorizedError)).rejects.toBe(
                unauthorizedError,
            );
        });

        expect(screen.getByText("Signed out")).toBeInTheDocument();
    });
});
