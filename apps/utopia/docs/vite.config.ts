import { readFileSync } from 'node:fs'
import { defineConfig } from 'vite'
import { tanstackStart } from '@tanstack/react-start/plugin/vite'
import viteReact from '@vitejs/plugin-react'
import viteTsConfigPaths from 'vite-tsconfig-paths'
import tailwindcss from '@tailwindcss/vite'

/**
 * The migration dashboard's pages. They show only in migration mode, so no
 * prerendered page links to them for the crawler to find. The old /appwrite
 * pages redirect to them, and are prerendered too so their links keep working.
 */
function migrationPages(): { path: string }[] {
  const tabs = ['utopia', 'services', 'hooks', 'facts', 'tasks'].map((t) => `/migration/${t}`)
  const old = ['/appwrite', '/appwrite/facts', '/appwrite/tasks', '/appwrite/behaviour', '/appwrite/benchmarks']
  let services: string[] = []
  try {
    const data = JSON.parse(readFileSync(new URL('./data/appwrite.json', import.meta.url), 'utf8')) as { services: { id: string }[] }
    services = data.services.map((s) => `/migration/services/${s.id}`)
  } catch {
    // No migration data: the services tab shows how to generate it.
  }
  return ['/migration', ...tabs, ...services, ...old].map((path) => ({ path }))
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
      pages: [...migrationPages(), { path: '/changelog' }],
    }),
    viteReact(),
  ],
})
