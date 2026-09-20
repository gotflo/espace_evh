import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// En developpement, les appels /api sont rediriges vers le backend Laravel (port 8000),
// ce qui evite les problemes de CORS. En production, Laravel sert le build et /api est local.
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/storage': 'http://127.0.0.1:8000',
    },
  },
})
