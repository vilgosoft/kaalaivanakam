import { defineConfig } from 'vite'

// Prebuilt SPA lives in public/ (recovered bundle). Proxy /v1 to local PHP in dev.
export default defineConfig({
  server: {
    proxy: {
      '/v1': {
        target: 'http://127.0.0.1:8888',
        changeOrigin: true,
      },
    },
  },
})
