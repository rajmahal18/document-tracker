import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: './',
  build: {
    outDir: '../../public/org-chart-react',
    manifest: 'manifest.json',
    sourcemap: true,
    emptyOutDir: true,
  },
})
