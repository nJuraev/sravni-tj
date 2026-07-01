import { fileURLToPath, URL } from 'node:url'
import { defineConfig, type Plugin } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// `defineConfig` comes from vitest/config so the `test` block is typed.
// vitest bundles its own (older) vite copy, so @vitejs/plugin-vue — typed
// against the root vite — is structurally compatible but nominally distinct;
// the cast bridges the two vite type trees without affecting runtime.
export default defineConfig({
  plugins: [vue() as Plugin],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'happy-dom',
    globals: true,
    // No spec files yet; keep `npm run test` green until tests are added.
    passWithNoTests: true,
  },
})
