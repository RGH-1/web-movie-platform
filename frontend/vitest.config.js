import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
  define: {
    'import.meta.env.VITE_API_BASE_URL': JSON.stringify('http://127.0.0.1:8000/api'),
  },
  test: {
    environment: 'jsdom',
    include: ['tests/ui/**/*.test.jsx'],
    setupFiles: ['tests/ui/setup.js'],
    clearMocks: true,
  },
});
