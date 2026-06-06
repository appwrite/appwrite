import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import { tanstackStart } from '@tanstack/react-start/plugin/vite'
import viteReact from '@vitejs/plugin-react'
import viteTsConfigPaths from 'vite-tsconfig-paths'
import tailwindcss from '@tailwindcss/vite'
import devtoolsJson from 'vite-plugin-devtools-json'

const projectRoot = path.dirname(fileURLToPath(import.meta.url))
const justBashBrowserEntry = path.resolve(
  projectRoot,
  'node_modules/just-bash/dist/bundle/browser.js',
)
const sprintfJsShim = path.resolve(
  projectRoot,
  'src/lib/cli-shell/shims/sprintf-js.ts',
)
const almostnodeSrc = path.resolve(projectRoot, 'node_modules/almostnode/src')

export default defineConfig(async () => {
  const sentryPlugins =
    process.env.VITE_SENTRY_DSN && process.env.SENTRY_AUTH_TOKEN
      ? [
          (
            await import('@sentry/tanstackstart-react/vite')
          ).sentryTanstackStart({
            org: 'appwrite',
            project: 'console-v4',
            authToken: process.env.SENTRY_AUTH_TOKEN,
          }),
        ]
      : []

  return {
    plugins: [
      // this is the plugin that enables path aliases
      viteTsConfigPaths({
        projects: ['./tsconfig.json'],
      }),
      tailwindcss(),
      tanstackStart(),
      devtoolsJson(),
      viteReact(),
      ...sentryPlugins,
    ],
    server: {
      host: '::',
      allowedHosts: true,
      hmr: true,
      // TanStack Router writes this file; watching it retriggers generation in a loop.
      watch: {
        ignored: ['**/src/routeTree.gen.ts'],
      },
    },
    resolve: {
      dedupe: ['react', 'react-dom', 'use-sync-external-store'],
      alias: [
        {
          find: /^sprintf-js$/,
          replacement: sprintfJsShim,
        },
        {
          find: /^just-bash$/,
          replacement: justBashBrowserEntry,
        },
        {
          find: '@almostnode-internal/registry',
          replacement: path.join(almostnodeSrc, 'npm/registry.ts'),
        },
        {
          find: '@almostnode-internal/tarball',
          replacement: path.join(almostnodeSrc, 'npm/tarball.ts'),
        },
        {
          find: '@almostnode-internal/transform',
          replacement: path.join(almostnodeSrc, 'transform.ts'),
        },
        {
          find: '@almostnode-internal/path',
          replacement: path.join(almostnodeSrc, 'shims/path.ts'),
        },
        {
          find: /^almostnode$/,
          replacement: path.resolve(
            projectRoot,
            'node_modules/almostnode/dist/index.mjs',
          ),
        },
      ],
    },
    optimizeDeps: {
      // Recharts uses decimal.js (via victory-vendor/d3-scale) for tick calculations.
      // Force ESM interop so `new Decimal()` works when pre-bundled.
      needsInterop: [
        'decimal.js',
        'decimal.js-light',
        'sprintf-js',
        'sprintf-js/src/sprintf.js',
        // CJS entry re-exports `useSyncExternalStoreWithSelector`; pre-bundle so named ESM imports work
        // (recharts).
        'use-sync-external-store/shim/with-selector.js',
      ],
      include: [
        'decimal.js',
        'decimal.js-light',
        'recharts',
        'use-sync-external-store',
        'use-sync-external-store/shim/with-selector.js',
        'sprintf-js/src/sprintf.js',
      ],
      // Serve TanStack store packages as native ESM. Pre-bundling cached an older
      // @tanstack/react-store without createAtom when router upgraded first.
      exclude: ['@tanstack/react-store', '@tanstack/store', 'almostnode'],
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
  }
})
