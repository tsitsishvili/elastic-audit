import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  publicDir: false,
  build: {
    emptyOutDir: true,
    manifest: 'manifest.json',
    outDir: 'public/vendor/elastic-audit',
    rollupOptions: {
      input: {
        styles: resolve(import.meta.dirname, 'resources/css/elastic-audit.css'),
        alpine: resolve(import.meta.dirname, 'resources/js/alpine.js'),
        chart: resolve(import.meta.dirname, 'resources/js/chart.js'),
      },
      output: {
        assetFileNames: '[name]-[hash][extname]',
        entryFileNames: '[name]-[hash].js',
      },
    },
  },
});
