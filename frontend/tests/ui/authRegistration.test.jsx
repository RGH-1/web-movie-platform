import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { cleanup, fireEvent, render, screen } from "@testing-library/react";

import { MemoryRouter, Route, Routes } from "react-router-dom";

import Register from "../../src/pages/Register";
import { useAuth } from "../../src/hooks/useAuth";

// Mock the authentication hook so tests do not
// depend on the real Laravel backend.
vi.mock("../../src/hooks/useAuth", () => ({
    useAuth: vi.fn(),
}));

let signUp;

function renderRegistration() {
    return render(
        <MemoryRouter initialEntries={["/register"]}>
            <Routes>
                <Route path="/register" element={<Register />} />

                <Route path="/" element={<h1>Home</h1>} />
            </Routes>
        </MemoryRouter>,
    );
}

function fillValidForm() {
    fireEvent.change(screen.getByLabelText("Full name"), {
        target: { value: "Test User" },
    });

    fireEvent.change(screen.getByLabelText("Email address"), {
        target: { value: "test@example.com" },
    });

    fireEvent.change(screen.getByLabelText("Password"), {
        target: { value: "TestPassword123!" },
    });

    fireEvent.change(screen.getByLabelText("Confirm password"), {
        target: { value: "TestPassword123!" },
    });
}

beforeEach(() => {
    signUp = vi.fn();

    useAuth.mockReturnValue({
        signUp,
    });
});

afterEach(() => {
    cleanup();
    vi.resetAllMocks();
});

describe("Registration flow (AB#29)", () => {
    it("rejects empty fields without calling Laravel", () => {
        renderRegistration();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Create account",
            }),
        );

        expect(screen.getByText("Name is required.")).toBeInTheDocument();

        expect(screen.getByText("Email is required.")).toBeInTheDocument();

        expect(screen.getByText("Password is required.")).toBeInTheDocument();

        expect(
            screen.getByText("Please confirm your password."),
        ).toBeInTheDocument();

        expect(signUp).not.toHaveBeenCalled();
    });

    it("displays a duplicate email error from Laravel", async () => {
        signUp.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    errors: {
                        email: ["The email has already been taken."],
                    },
                },
            },
        });

        renderRegistration();
        fillValidForm();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Create account",
            }),
        );

        expect(
            await screen.findByText("The email has already been taken."),
        ).toBeInTheDocument();

        expect(screen.getByRole("alert")).toHaveTextContent(
            "Please correct the highlighted fields.",
        );

        expect(signUp).toHaveBeenCalledTimes(1);
    });

    it("redirects to the homepage after successful registration", async () => {
        signUp.mockResolvedValue({
            id: 1,
            name: "Test User",
            email: "test@example.com",
        });

        renderRegistration();
        fillValidForm();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Create account",
            }),
        );

        expect(
            await screen.findByRole("heading", {
                name: "Home",
            }),
        ).toBeInTheDocument();

        expect(signUp).toHaveBeenCalledTimes(1);
    });

    it.each([
        [
            "an invalid email",
            "Email address",
            "invalid-email",
            "Enter a valid email address.",
        ],
        [
            "a short password",
            "Password",
            "short",
            "Password must contain at least 12 characters.",
        ],
        [
            "mismatched passwords",
            "Confirm password",
            "DifferentPassword123!",
            "Passwords do not match.",
        ],
    ])(
        "rejects %s without calling Laravel",
        (scenario, field, value, expectedError) => {
            renderRegistration();
            fillValidForm();

            fireEvent.change(screen.getByLabelText(field), {
                target: { value },
            });

            fireEvent.click(
                screen.getByRole("button", {
                    name: "Create account",
                }),
            );

            expect(screen.getByText(expectedError)).toBeInTheDocument();

            expect(signUp).not.toHaveBeenCalled();
        },
    );

    it("handles an expired security session (HTTP 419)", async () => {
        signUp.mockRejectedValue({
            response: {
                status: 419,
            },
        });

        renderRegistration();
        fillValidForm();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Create account",
            }),
        );

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Your security session expired. Please try again.",
        );

        expect(signUp).toHaveBeenCalledTimes(1);
    });

    it("shows a safe message when registration fails (HTTP 500)", async () => {
        signUp.mockRejectedValue({
            response: {
                status: 500,
                data: {
                    message: "Sensitive internal database error",
                },
            },
        });

        renderRegistration();
        fillValidForm();

        fireEvent.click(
            screen.getByRole("button", {
                name: "Create account",
            }),
        );

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Registration failed. Please try again.",
        );

        expect(
            screen.queryByText("Sensitive internal database error"),
        ).not.toBeInTheDocument();
    });
});
