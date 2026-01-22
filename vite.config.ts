import { defineConfig } from 'vite'
import { tanstackStart } from '@tanstack/react-start/plugin/vite'
import viteReact from '@vitejs/plugin-react'
import viteTsConfigPaths from 'vite-tsconfig-paths'
import tailwindcss from '@tailwindcss/vite'
import devtoolsJson from 'vite-plugin-devtools-json'
import { nitroV2Plugin } from '@tanstack/nitro-v2-vite-plugin'

const forSites = process.env?.FOR_SITES === 'true'

const config = defineConfig({
  plugins: [
    // this is the plugin that enables path aliases
    viteTsConfigPaths({
      projects: ['./tsconfig.json'],
    }),
    tailwindcss(),
    tanstackStart({
      // Disable SSR - run as SPA (Single Page Application) only
      spa: {
        enabled: true,
      },
      prerender: {
        enabled: true,
        // Enable if you need pages to be at `/page/index.html` instead of `/page.html`
        // Useful for static hosting platforms
        autoSubfolderIndex: true,
        // Automatically discover and prerender static routes
        autoStaticPathsDiscovery: true,
        // Disable link crawling to prevent infinite loops on auth routes with redirect params
        crawlLinks: false,
        // Don't fail build if prerendering encounters an error (some routes like /reset require search params)
        failOnError: false,
        // Filter out routes that require search params or are dynamic
        filter: ({ path }) => {
          // Exclude routes that require search params (they'll be handled client-side)
          if (path === '/reset') return false
          // Exclude authentication routes - they handle redirects client-side and cause infinite loops
          if (
            path.startsWith('/sign-in') ||
            path.startsWith('/sign-up') ||
            path.startsWith('/mfa')
          ) {
            return false
          }
          // Exclude any routes with query parameters (they're dynamic and shouldn't be prerendered)
          if (path.includes('?')) return false
          return true
        },
      },
    }),
    // Enable Nitro plugin to generate .output directory for deployment
    // This is required for Appwrite deployment which expects .output/
    nitroV2Plugin({
      compatibilityDate: '2025-10-08',
      preset: 'node',
    }),
    devtoolsJson(),
    viteReact(),
  ],
  server: {
    host: '::',
    allowedHosts: true,
    hmr: true,
  },
})

export default config
