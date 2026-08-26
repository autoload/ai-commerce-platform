import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    // Required so the dev server is reachable from outside the container
    // (default Vite binds to 127.0.0.1 only).
    host: true,
    port: 5173,
    strictPort: true,
    // Windows host -> Docker Desktop (WSL2) bind mounts don't always
    // propagate native filesystem-change events reliably; polling trades a
    // small amount of CPU for correctly detecting host-side edits.
    watch: {
      usePolling: true,
    },
  },
})
