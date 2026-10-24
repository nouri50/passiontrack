import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    watch: {
      // Sous Docker (Windows), le conteneur n'est pas prévenu quand un fichier
      // change sur le disque (git pull, modification dans VS Code) : Vite
      // continuerait alors de servir l'ancienne version. WATCH_POLLING=true
      // (défini dans docker-compose.yml) lui fait vérifier les fichiers à
      // intervalle régulier. Sans Docker, la variable n'existe pas : rien ne change.
      usePolling: process.env.WATCH_POLLING === 'true',
      interval: 1000,
    },
  },
})
