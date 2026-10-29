import { useEffect, useState } from 'react';
import api from '../services/api';

function ApiHealthCheck() {
    const [status, setStatus] = useState('checking');

    useEffect(() => {
        const checkApi = async () => {
            try {
                const response = await api.get('/health');

                if (response.status === 200 && response.data.status === 'ok') {
                    setStatus('connected');
                } else {
                    setStatus('error');
                }
            } catch (error) {
                console.error('API health check failed:', error);
                setStatus('disconnected');
            }
        };

        checkApi();
    }, []);

    return (
        <div className="mt-4">
            <h2>Backend API Status</h2>

            {status === 'checking' && (
                <p>Checking API connection...</p>
            )}

            {status === 'connected' && (
                <p className="text-success">
                    Connected to the Laravel API.
                </p>
            )}

            {status === 'disconnected' && (
                <p className="text-danger">
                    Unable to connect to the Laravel API.
                </p>
            )}

            {status === 'error' && (
                <p className="text-warning">
                    The API responded, but its health status was unexpected.
                </p>
            )}
        </div>
    );
}

export default ApiHealthCheck;