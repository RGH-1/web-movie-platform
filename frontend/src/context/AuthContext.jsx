import { createContext, useEffect, useState } from "react";
import {
    getCurrentUser,
    loginUser,
    registerUser,
    logoutUser,
} from "../services/auth";
import api from "../services/api";
import { readProfileCompletion, completionFromError } from "../utils/profileCompletion";

export const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [profileCompletion, setProfileCompletion] = useState(null);
    const accountId = user?.id;

    const isAuthenticated = user !== null;

    useEffect(() => {
        let active = true;

        async function loadCurrentUser() {
            try {
                const currentUser = await getCurrentUser();

                if (active) {
                    setUser(currentUser);
                }
            } catch (error) {
                if (active) {
                    setUser(null);
                    setProfileCompletion(null);

                    if (error.response?.status !== 401) {
                        console.error("Failed to load current user:", error);
                    }
                }
            } finally {
                if (active) {
                    setLoading(false);
                }
            }
        }

        loadCurrentUser();

        return () => {
            active = false;
        };
    }, []);

    useEffect(() => {
        const interceptorId = api.interceptors.response.use(
            (response) => {
                if (response.config?.url?.split('?')[0] === '/v1/me/profile' && !response.config?.signal?.aborted) {
                    setProfileCompletion(readProfileCompletion(response.data?.meta));
                }
                return response;
            },

            (error) => {
                const isUnauthorized = error.response?.status === 401;

                const isLoginRequest =
                    error.config?.url?.includes("/v1/auth/login");

                if (isUnauthorized && !isLoginRequest) {
                    setUser(null);
                    setProfileCompletion(null);
                }

                const completion = completionFromError(error);
                if (completion && !error.config?.signal?.aborted) {
                    setProfileCompletion(completion);
                }
                return Promise.reject(error);
            },
        );

        return () => {
            api.interceptors.response.eject(interceptorId);
        };
    }, []);

    // Login/register responses contain account data only. Refresh completion
    // from Laravel for that account, without blocking unrelated pages.
    useEffect(() => {
        if (!accountId) return;
        const controller = new AbortController();
        api.get('/v1/me/profile', { signal: controller.signal }).catch(() => {
            // Unknown status is not evidence of an incomplete profile.
            // Existing interceptors handle 401 and explicit completion denial.
        });
        return () => controller.abort();
    }, [accountId]);

    async function signIn(email, password) {
        const loggedInUser = await loginUser(email, password);

        setProfileCompletion(null);
        setUser(loggedInUser);

        return loggedInUser;
    }

    async function signUp(formData) {
        const registeredUser = await registerUser(formData);

        setProfileCompletion(null);
        setUser(registeredUser);

        return registeredUser;
    }

    async function signOut() {
        try {
            await logoutUser();
        } catch (error) {
            if (error.response?.status !== 401) {
                throw error;
            }
        }

        setUser(null);
        setProfileCompletion(null);
    }

    const value = {
        user,
        setUser,
        loading,
        setLoading,
        isAuthenticated,
        profileCompletion,
        signIn,
        signUp,
        signOut,
    };

    return (
        <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
    );
}
