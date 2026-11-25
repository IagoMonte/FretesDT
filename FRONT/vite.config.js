import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    proxy: { '/api': process.env.API_PROXY || 'http://127.0.0.1:8000' },
  },
  preview: { proxy: { '/api': process.env.API_PROXY || 'http://127.0.0.1:8000' } },
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['test/units/**/*.spec.js'],
    setupFiles: ['test/setup.js'],
  },
})
