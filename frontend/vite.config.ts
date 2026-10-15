/// <reference types="vitest/config" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { VitePWA } from 'vite-plugin-pwa';
import path from 'node:path';

// Brumy — mobile-first PWA
export default defineConfig({
  plugins: [
    react(),
    !process.env.VITEST &&
    VitePWA({
      // Il SW lo registra l'app (lib/pwaUpdate): niente registerSW.js generato, così il controllo
      // degli aggiornamenti e l'avviso di nuova versione stanno nel bundle (CSP script-src 'self').
      injectRegister: false,
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,webp,woff2}'],
      },
      manifest: {
        name: 'Brumy — Gestione veicoli',
        short_name: 'Brumy',
        description: 'Manutenzioni, rifornimenti, spese e scadenze per i tuoi veicoli',
        theme_color: '#0f172a',          // slate-900
        background_color: '#0f172a',
        display: 'standalone',
        orientation: 'portrait-primary',  // mobile-first
        scope: '/',
        start_url: '/',
        lang: 'it',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
    }),
  ].filter(Boolean),
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    include: ['src/**/*.test.{ts,tsx}'],
    restoreMocks: true,
  },
  // Niente dev server: `npm run watch` (vite build --watch) ricostruisce dist/
  // in continuo, servita da FrankenPHP/Caddy sia in dev (docker/app/Caddyfile.dev)
  // sia in prod (Dockerfile copia dist/ nell'immagine). Stesso outDir in
  // entrambi gli ambienti, niente dev-server/proxy/HMR da tenere in sync.
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    // Solo con `vite build --watch` (script "watch", Lando): mettere questa
    // chiave incondizionatamente fa entrare in watch mode ANCHE `vite build`
    // one-shot (script "build", usato dalla build Docker prod) — il processo
    // chokidar (qui in più con usePolling) non termina mai, e `RUN npm run
    // build` nel Dockerfile resta appeso in eterno aspettando che il comando
    // finisca (bloccava la build di ore, non minuti).
    watch: process.argv.includes('--watch')
      ? {
          // Polling: su Docker/macOS il bind mount non emette eventi fs nativi,
          // altrimenti `vite build --watch` smette silenziosamente di ricostruire
          // dopo un po' (bundle stale servito da Caddy senza nessun errore visibile).
          chokidar: {
            usePolling: true,
            interval: 500,
          },
        }
      : null,
    rollupOptions: {
      output: {
        // Manual chunking for vendor split
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          query: ['@tanstack/react-query'],
          forms: ['react-hook-form', '@hookform/resolvers', 'zod'],
          ui: ['lucide-react', 'class-variance-authority', 'clsx', 'tailwind-merge'],
          // recharts solo in un chunk con nome proprio: lo importa in lazy ChartsGrid, quindi
          // index.html NON lo precarica (verificare con una build in una cartella scratch).
          charts: ['recharts'],
          i18n: ['i18next', 'react-i18next', 'i18next-browser-languagedetector'],
        },
      },
    },
  },
});
