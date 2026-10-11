import { readFileSync } from 'node:fs'
import { defineConfig } from 'vite'
import { tanstackStart } from '@tanstack/react-start/plugin/vite'
import viteReact from '@vitejs/plugin-react'
import viteTsConfigPaths from 'vite-tsconfig-paths'
import tailwindcss from '@tailwindcss/vite'

/**
 * The Appwrite migration pages. They show only in migration mode, so no
 * prerendered page links to them for the crawler to find.
 */
function migrationPages(): { path: string }[] {
  try {
    const data = JSON.parse(readFileSync(new URL('./data/appwrite.json', import.meta.url), 'utf8')) as { services: { id: string }[] }
    return [{ path: '/appwrite' }, ...data.services.map((s) => ({ path: `/appwrite/${s.id}` }))]
  } catch {
    return []
  }
}

export default defineConfig({
  server: {
    port: 3200,
    // TanStack Router writes this file; watching it retriggers generation in a loop.
    watch: { ignored: ['**/src/routeTree.gen.ts'] },
  },
  // IPv4: prerendering fetches every page from this server, and `localhost`
  // resolving to ::1 first makes a request that waits on a long render time
  // out on ::1, then get refused on 127.0.0.1, failing the build.
  preview: { port: 3200, host: '127.0.0.1' },
  plugins: [
    viteTsConfigPaths(),
    tailwindcss(),
    tanstackStart({
      prerender: {
        enabled: true,
        crawlLinks: true,
        failOnError: true,
      },
      pages: migrationPages(),
    }),
    viteReact(),
  ],
})
