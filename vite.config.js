import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import vuetify from 'vite-plugin-vuetify';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const port = Number(env.VITE_PORT) || 5173;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            vue({
                template: { transformAssetUrls: { base: null, includeAbsolute: false } },
            }),
            vuetify({ autoImport: true }),   // компоненты подключаются автоматически
            tailwindcss(),                   // без него app.css отдаётся как есть и утилиты Tailwind не генерируются
        ],
        server: {
            host: '0.0.0.0',
            port,
            strictPort: true,
            origin: `http://localhost:${port}`,
            cors: true,
            hmr: { host: 'localhost', clientPort: port },
            //watch: { usePolling: true },
            //грузит CPU
        },
    };
});