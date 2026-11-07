import process from 'node:process'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

const releaseMetadata = {
  name: 'release-metadata',
  generateBundle() {
    this.emitFile({ type: 'asset', fileName: 'release.json', source: JSON.stringify({
      release_sha: process.env.RENDER_GIT_COMMIT || process.env.RELEASE_SHA || 'development',
      api_origin: process.env.VITE_API_URL ? new URL(process.env.VITE_API_URL).origin : null,
    }) })
  },
}
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss(), releaseMetadata],
  server: {
    host: true,
    port: 5173,
  },
})
