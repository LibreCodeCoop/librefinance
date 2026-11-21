// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'node:path'

export default defineConfig({
  plugins: [vue()],
  // Vite library/IIFE mode does not inline Node's process.env by default.
  // The browser has no process global; compile these constants explicitly.
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    'process.env.NODE_DEBUG': JSON.stringify(''),
  },
  build: {
    outDir: 'js',
    emptyOutDir: true,
    cssCodeSplit: false,
    lib: {
      entry: resolve('src/main.js'),
      name: 'LibreFinance',
      formats: ['iife'],
      fileName: () => 'main.js',
      cssFileName: 'style',
    },
    rollupOptions: {
      output: {
        assetFileNames: (asset) => asset.name === 'style.css' ? 'style.css' : 'assets/[name]-[hash][extname]',
      },
    },
  },
})
