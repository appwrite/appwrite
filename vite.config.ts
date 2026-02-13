/// <reference types="vitest" />
import { defineConfig } from 'vitest/config'
import { tanstackStart } from '@tanstack/react-start/plugin/vite'
import { sentryTanstackStart } from '@sentry/tanstackstart-react'
import viteReact from '@vitejs/plugin-react'
import viteTsConfigPaths from 'vite-tsconfig-paths'
import tailwindcss from '@tailwindcss/vite'
import devtoolsJson from 'vite-plugin-devtools-json'

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
        // autoStaticPathsDiscovery: true,
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
    devtoolsJson(),
    viteReact(),
    // Sentry plugin for source maps upload (only when Sentry is enabled via VITE_SENTRY_DSN)
    ...(process.env.VITE_SENTRY_DSN && process.env.SENTRY_AUTH_TOKEN
      ? [
          sentryTanstackStart({
            org: 'appwrite',
            project: 'console-v4',
            authToken: process.env.SENTRY_AUTH_TOKEN,
          }),
        ]
      : []),
  ] as any,
  server: {
    host: '::',
    allowedHosts: true,
    hmr: true,
  },
  resolve: {
    dedupe: ['react', 'react-dom'],
  },
  preview: {
    port: 4173,
    host: '::',
  },
  build: {
    outDir: 'dist',
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./vitest.setup.ts'],
    css: false,
    include: [
      'src/**/*.{test,spec}.{ts,tsx}',
      'tests/**/*.{test,spec}.{ts,tsx}',
    ],
    exclude: ['e2e/**', 'node_modules/**'],
  },
})

export default config
