import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig(({ command, mode, isSsrBuild }) => {
  const env = loadEnv(mode, process.cwd(), '')

  // Mocks are ON by default in `vite dev` so the app runs without a backend.
  // An explicit VITE_USE_MOCKS (from shell or .env) always wins. In `build`
  // the default is OFF — production talks to VITE_API_BASE_URL.
  const useMocks =
    env.VITE_USE_MOCKS !== undefined ? env.VITE_USE_MOCKS : command === 'serve' ? 'true' : 'false'

  return {
    plugins: [vue()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    define: {
      'import.meta.env.VITE_USE_MOCKS': JSON.stringify(useMocks),
    },
    // Two-pass SSR build: `vite build` (client) and `vite build --ssr
    // src/entry-server.ts` (server) each go to their own dir — server/index.js
    // reads dist/client for static assets + template, dist/server for render().
    build: {
      outDir: isSsrBuild ? 'dist/server' : 'dist/client',
    },
    server: {
      host: true,
      port: Number(process.env.PORT) || 5173,
    },
    // `vite preview` обслуживает собранный dist на Railway (SPA-fallback по
    // умолчанию). allowedHosts: true — иначе Railway-домен отклоняется.
    preview: {
      host: true,
      allowedHosts: true,
      // Railway задаёт порт через $PORT; startCommand его не раскрывает,
      // поэтому берём из окружения здесь (fallback 4173 для локали).
      port: Number(process.env.PORT) || 4173,
    },
  }
})
