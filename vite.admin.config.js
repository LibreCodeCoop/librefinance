// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'node:path'

export default defineConfig({
  plugins: [vue()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    'process.env.NODE_DEBUG': JSON.stringify(''),
  },
  build: {
    outDir: 'js',
    emptyOutDir: false,
    cssCodeSplit: false,
    lib: {
      entry: resolve('src/admin/main.js'),
      name: 'LibreFinanceAdmin',
      formats: ['iife'],
      fileName: () => 'admin.js',
      cssFileName: 'admin-style',
    },
  },
})
