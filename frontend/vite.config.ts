import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * In production Nginx serves the built app and the PHP API from one origin,
 * with /api going to PHP-FPM (see docs/DEPLOYMENT.md). The dev server does the
 * same through a proxy rather than pointing the app at a second origin.
 *
 * That is not only convenience. The session cookie is HttpOnly with
 * SameSite=Lax, so a genuinely cross-site API would not receive it on a reload
 * and the app would appear to sign the user out. Proxying keeps development
 * behaving exactly like production, and leaves CORS out of it entirely.
 */
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, fileURLToPath(new URL('.', import.meta.url)), '');
  const apiTarget = env.VITE_DEV_API_PROXY || 'http://127.0.0.1:8080';

  return {
    plugins: [react()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      port: 5173,
      strictPort: false,
      proxy: {
        '/api': {
          target: apiTarget,
          changeOrigin: false,
        },
      },
    },
    build: {
      outDir: 'dist',
      sourcemap: false,
      // Vendor code is split out so the app shell stays small; the route
      // components are code-split separately via React.lazy in the router.
      rollupOptions: {
        output: {
          // A function rather than the map form. With the map, Rollup put
          // React's own internals in the router chunk - react-router-dom
          // reaches them first - and left an all-but-empty react chunk
          // behind. Deciding per module keeps React where it belongs, so a
          // router upgrade does not invalidate React in everyone's cache.
          manualChunks(id: string) {
            if (!id.includes('node_modules')) {
              return undefined;
            }

            if (/node_modules\/(react-router|@remix-run)/.test(id)) {
              return 'router';
            }

            if (/node_modules\/(react|react-dom|scheduler)\//.test(id)) {
              return 'react';
            }

            return 'vendor';
          },
        },
      },
    },
  };
});
