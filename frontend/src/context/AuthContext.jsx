
import { createContext, useEffect, useState } from 'react';
import {
    getCurrentUser,
    loginUser,
    registerUser
} from '../services/auth';
import api from '../services/api';

export const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);

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

                    if (error.response?.status !== 401) {
                        console.error('Failed to load current user:', error);
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
        (response) => response,

        (error) => {
            const isUnauthorized = error.response?.status === 401;

            const isLoginRequest =
                error.config?.url?.includes('/v1/auth/login');

            if (isUnauthorized && !isLoginRequest) {
                setUser(null);
            }

            return Promise.reject(error);
        }
    );

    return () => {
        api.interceptors.response.eject(interceptorId);
    };
}, []);


async function signIn(email, password) {
    const loggedInUser = await loginUser(email, password);

    setUser(loggedInUser);

    return loggedInUser;
}

async function signUp(formData) {
    const registeredUser = await registerUser(formData);

    setUser(registeredUser);

    return registeredUser;
}



const value = {
    user,
    setUser,
    loading,
    setLoading,
    isAuthenticated,
    signIn,
    signUp

};


    return (
        <AuthContext.Provider value={value}>
            {children}
        </AuthContext.Provider>
    );
}
