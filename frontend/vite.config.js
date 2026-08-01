import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    // Dev-only: proxy API/auth calls to `php artisan serve` (default
    // 127.0.0.1:8000) so the SPA and API share an origin and Sanctum's
    // cookie-based auth works without extra CORS configuration. Production
    // instead has Nginx serve this build and proxy /api to PHP-FPM (v2 §11).
    proxy: {
      '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
      '/sanctum': { target: 'http://127.0.0.1:8000', changeOrigin: true },
    },
  },
})
