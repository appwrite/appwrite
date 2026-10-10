import { defineConfig } from 'vite'
import { tanstackStart } from '@tanstack/react-start/plugin/vite'
import viteReact from '@vitejs/plugin-react'
import viteTsConfigPaths from 'vite-tsconfig-paths'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  server: {
    port: 3100,
    // TanStack Router writes this file; watching it retriggers generation in a loop.
    watch: { ignored: ['**/src/routeTree.gen.ts'] },
  },
  preview: { port: 3100 },
  plugins: [viteTsConfigPaths(), tailwindcss(), tanstackStart(), viteReact()],
})
