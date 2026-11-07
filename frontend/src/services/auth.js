
import api from './api';

// Initialize CSRF protection before registration.
const csrfUrl = new URL(
    '/sanctum/csrf-cookie',
    api.defaults.baseURL
).href;

export async function registerUser(formData) {
    // Step 1: Ask Laravel to initialize CSRF cookies.
    await api.get(csrfUrl);

    // Step 2: Send the registration information.
    const response = await api.post(
        '/v1/auth/register',
        formData
    );

    // Step 3: Return the newly registered user's data.
    return response.data.data;
}
