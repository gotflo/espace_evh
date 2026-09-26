import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'

/**
 * Politique de securite du contenu (CSP), ajoutee au build de production seulement
 * (le serveur de developpement a besoin de scripts en ligne). Elle limite ce que la page
 * peut charger : scripts de l'application et du lecteur YouTube uniquement, aucune
 * connexion vers un autre site que le notre. Meme si un texte malveillant arrivait a
 * s'afficher, il ne pourrait ni executer de script ni envoyer de donnees ailleurs.
 */
const CSP = [
  "default-src 'self'",
  "script-src 'self' https://www.youtube.com https://s.ytimg.com",
  "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
  "font-src 'self' data: https://fonts.gstatic.com",
  "img-src 'self' data: blob: https://i.ytimg.com",
  "frame-src https://www.youtube-nocookie.com https://www.youtube.com",
  "connect-src 'self'",
  "media-src 'self' blob:",
  "worker-src 'self'",
  "manifest-src 'self'",
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
].join('; ')

function contentSecurityPolicy(): Plugin {
  return {
    name: 'evh-csp',
    apply: 'build',
    transformIndexHtml: (html) => html.replace('<head>', `<head>\n    <meta http-equiv="Content-Security-Policy" content="${CSP}" />`),
  }
}

// En developpement, les appels /api sont rediriges vers le backend Laravel (port 8000),
// ce qui evite les problemes de CORS. En production, Laravel sert le build et /api est local.
export default defineConfig({
  plugins: [react(), contentSecurityPolicy()],
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/storage': 'http://127.0.0.1:8000',
    },
  },
  build: {
    // pdfmake (≈ 2 Mo) n'est charge qu'au moment d'un export PDF.
    chunkSizeWarningLimit: 1500,
    rollupOptions: {
      output: {
        // Les librairies changent rarement : on les isole pour que le
        // navigateur les garde en cache entre deux mises a jour de l'app.
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          phone: ['libphonenumber-js'],
        },
      },
    },
  },
})
