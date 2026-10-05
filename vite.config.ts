import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue(),
    ],
    server: {
        host: '0.0.0.0',
        // Same port inside and outside Docker: the hot file sends the browser to localhost:<port>.
        port: Number(process.env.VITE_PORT || 5173),
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
    },
});
