import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const environment = loadEnv(mode, process.cwd(), '');
    const devServerHost = environment.VITE_DEV_SERVER_HOST || '127.0.0.1';

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: devServerHost,
            hmr: {
                host: devServerHost,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
