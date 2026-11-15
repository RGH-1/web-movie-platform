import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { cleanup, fireEvent, render, screen } from "@testing-library/react";

import { MemoryRouter, Route, Routes } from "react-router-dom";

import Login from "../../src/pages/Login";
import { useAuth } from "../../src/hooks/useAuth";

// Replace the real authentication hook with a mock.
vi.mock("../../src/hooks/useAuth", () => ({
    useAuth: vi.fn(),
}));

let signIn;

// Render the login page with React Router.
function renderLogin(initialPath = "/login") {
    return render(
        <MemoryRouter initialEntries={[initialPath]}>
            <Routes>
                <Route path="/login" element={<Login />} />
                <Route path="/" element={<h1>Home</h1>} />
            </Routes>
        </MemoryRouter>,
    );
}

// Fill the login form with valid input.
function fillValidLogin() {
    fireEvent.change(screen.getByLabelText("Email address"), {
        target: {
            value: "test@example.com",
        },
    });

    fireEvent.change(screen.getByLabelText("Password"), {
        target: {
            value: "TestPassword123!",
        },
    });
}

beforeEach(() => {
    signIn = vi.fn();

    useAuth.mockReturnValue({
        signIn,
    });
});

afterEach(() => {
    cleanup();
    vi.resetAllMocks();
});

describe("Login flow (AB#29)", () => {
    // TEST 1: Successful login
    it("redirects to the homepage after successful login", async () => {
        signIn.mockResolvedValue({
            id: 1,
            name: "Test User",
            email: "test@example.com",
        });

        renderLogin();
        fillValidLogin();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Log in",
            }),
        );

        expect(
            await screen.findByRole("heading", {
                name: "Home",
            }),
        ).toBeInTheDocument();

        expect(signIn).toHaveBeenCalledWith(
            "test@example.com",
            "TestPassword123!",
        );

        expect(signIn).toHaveBeenCalledTimes(1);
    });

    // TEST 2: Empty fields
    it("rejects empty login fields without calling Laravel", () => {
        renderLogin();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Log in",
            }),
        );

        expect(screen.getByText("Email is required.")).toBeInTheDocument();

        expect(screen.getByText("Password is required.")).toBeInTheDocument();

        expect(signIn).not.toHaveBeenCalled();
    });

    // TEST 3: Incorrect credentials
    it("shows a safe error for incorrect credentials (HTTP 401)", async () => {
        signIn.mockRejectedValue({
            response: {
                status: 401,
                data: {
                    message: "Sensitive backend authentication information",
                },
            },
        });

        renderLogin();
        fillValidLogin();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Log in",
            }),
        );

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Invalid email or password.",
        );

        expect(
            screen.queryByText("Sensitive backend authentication information"),
        ).not.toBeInTheDocument();

        expect(signIn).toHaveBeenCalledTimes(1);
    });

    // TEST 4: Backend validation error
    it("displays Laravel validation errors (HTTP 422)", async () => {
        signIn.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    errors: {
                        email: ["Please provide a valid email address."],
                    },
                },
            },
        });

        renderLogin();
        fillValidLogin();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Log in",
            }),
        );

        expect(
            await screen.findByText("Please provide a valid email address."),
        ).toBeInTheDocument();

        expect(screen.getByRole("alert")).toHaveTextContent(
            "Please correct the highlighted fields.",
        );
    });

    it.each([
        [419, "Your security session expired. Please try again."],
        [429, "Too many login attempts. Please wait before trying again."],
        [500, "Unable to log in. Please try again later."],
    ])(
        "handles HTTP %i with a safe error message",
        async (status, expectedMessage) => {
            signIn.mockRejectedValue({
                response: {
                    status,
                    data: {
                        message: "Sensitive backend error details",
                    },
                },
            });

            renderLogin();
            fillValidLogin();

            fireEvent.click(screen.getByRole("button", { name: "Log in" }));

            expect(await screen.findByRole("alert")).toHaveTextContent(
                expectedMessage,
            );

            expect(
                screen.queryByText("Sensitive backend error details"),
            ).not.toBeInTheDocument();
        },
    );

    it("handles network failures without exposing technical details", async () => {
        signIn.mockRejectedValue(new Error("Network Error"));

        renderLogin();
        fillValidLogin();

        fireEvent.click(screen.getByRole("button", { name: "Log in" }));

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Unable to log in. Please try again later.",
        );

        expect(screen.queryByText("Network Error")).not.toBeInTheDocument();
    });

    it("redirects to the originally requested protected page", async () => {
        signIn.mockResolvedValue({
            id: 1,
            name: "Test User",
            email: "test@example.com",
        });

        render(
            <MemoryRouter
                initialEntries={[
                    {
                        pathname: "/login",
                        state: {
                            from: {
                                pathname: "/movies",
                                search: "",
                                hash: "",
                            },
                        },
                    },
                ]}
            >
                <Routes>
                    <Route path="/login" element={<Login />} />
                    <Route path="/movies" element={<h1>Movie Discovery</h1>} />
                </Routes>
            </MemoryRouter>,
        );

        fillValidLogin();

        fireEvent.click(screen.getByRole("button", { name: "Log in" }));

        expect(
            await screen.findByRole("heading", {
                name: "Movie Discovery",
            }),
        ).toBeInTheDocument();

        expect(signIn).toHaveBeenCalledWith(
            "test@example.com",
            "TestPassword123!",
        );
    });

    it.each([
        ["Google", "google"],
        ["GitHub", "github"],
        ["Facebook", "facebook"],
    ])(
        "renders the %s OAuth button with the correct Laravel redirect",
        (name, provider) => {
            renderLogin();

            const link = screen.getByRole("link", {
                name: `Continue with ${name}`,
            });

            const apiBaseUrl =
                import.meta.env.VITE_API_BASE_URL ||
                "http://localhost:8000/api";

            const backendOrigin = new URL(apiBaseUrl).origin;

            expect(link).toHaveAttribute(
                "href",
                `${backendOrigin}/auth/${provider}/redirect`,
            );

            expect(signIn).not.toHaveBeenCalled();
        },
    );

    it.each([
        ["cancelled", "Social login was cancelled. Please try again."],
        [
            "account_exists",
            "An account with this email already exists. Please use its existing login method.",
        ],
        [
            "invalid_state",
            "Your login session expired or was invalid. Please try again.",
        ],
        [
            "provider_failed",
            "Authentication with the provider failed. Please try again.",
        ],
    ])(
        "displays a safe message for OAuth error %s",
        async (errorCode, expectedMessage) => {
            renderLogin(`/login?oauth_error=${errorCode}`);

            expect(await screen.findByRole("alert")).toHaveTextContent(
                expectedMessage,
            );

            expect(signIn).not.toHaveBeenCalled();
        },
    );

    it.each(["unexpected_error", "constructor", "__proto__"])(
        "handles unknown OAuth error code %s safely",
        async (errorCode) => {
            renderLogin(`/login?oauth_error=${errorCode}`);

            expect(await screen.findByRole("alert")).toHaveTextContent(
                "Social login failed. Please try again.",
            );

            expect(signIn).not.toHaveBeenCalled();
        },
    );
});
