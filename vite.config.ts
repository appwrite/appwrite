import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import { tanstackStart } from '@tanstack/react-start/plugin/vite'
import viteReact from '@vitejs/plugin-react'
import viteTsConfigPaths from 'vite-tsconfig-paths'
import tailwindcss from '@tailwindcss/vite'
import devtoolsJson from 'vite-plugin-devtools-json'
import { docsContentHmrPlugin } from './src/lib/docs/vite-docs-content-hmr-plugin'
import { silenceAbortedSsrPlugin } from './src/lib/vite/silence-aborted-ssr-plugin'
import {
  getAllMarketingPrerenderPaths,
  getSitesPrerenderBuildSummary,
  isMarketingPrerenderPath,
} from './src/lib/marketing/marketing-build-paths'
import { getSitesPrerenderConcurrency } from './src/lib/marketing/sites-prerender-scope'
import {
  INIT_PRERENDER_PATHS,
  isInitPrerenderPath,
} from './src/lib/init/init-prerender-paths'

const projectRoot = path.dirname(fileURLToPath(import.meta.url))
const decimalJsLightShim = path.resolve(
  projectRoot,
  'src/lib/shims/decimal-js-light.ts',
)
const decimalJsShim = path.resolve(projectRoot, 'src/lib/shims/decimal-js.ts')

/**
 * Fail the client build when Rolldown emits empty hashed JS/CSS assets.
 * Empty re-export wrappers produce 0-byte chunks that other routes still import;
 * if the server skips them, the SPA fallback returns HTML and browsers throw MIME errors.
 */
function rejectEmptyBuildAssetsPlugin() {
  return {
    name: 'reject-empty-build-assets',
    apply: 'build' as const,
    generateBundle(
      _options: unknown,
      bundle: Record<
        string,
        {
          type: string
          fileName: string
          code?: string
          source?: string | Uint8Array
        }
      >,
    ) {
      const empty = Object.values(bundle).filter((item) => {
        if (item.type !== 'chunk' && item.type !== 'asset') return false
        if (!/\.(?:m?js|cjs|css)$/i.test(item.fileName)) return false
        if (item.type === 'chunk') return (item.code?.length ?? 0) === 0
        const source = item.source
        if (typeof source === 'string') return source.length === 0
        return source != null && source.byteLength === 0
      })
      if (empty.length === 0) return
      const names = empty.map((item) => item.fileName).join(', ')
      throw new Error(
        `Build emitted empty asset(s): ${names}. Remove empty re-export wrappers (import the real module directly).`,
      )
    },
  }
}
function getInitPrerenderPageEntries() {
  return INIT_PRERENDER_PATHS.map((path) => ({
    path,
    prerender: { enabled: true },
  }))
}

function getTanstackStartSitesOptions() {
  const prerenderConcurrency = getSitesPrerenderConcurrency()
  console.log(
    `[sites] ${getSitesPrerenderBuildSummary()} concurrency=${String(prerenderConcurrency)}`,
  )

  return {
    prerender: {
      enabled: true,
      crawlLinks: false,
      concurrency: prerenderConcurrency,
      failOnError: true,
      filter: ({ path }: { path: string }) =>
        isMarketingPrerenderPath(path) || isInitPrerenderPath(path),
    },
    pages: [
      ...getAllMarketingPrerenderPaths().map((path) => ({
        path,
        prerender: { enabled: true },
      })),
      ...getInitPrerenderPageEntries(),
    ],
  }
}

function getTanstackStartCloudOptions() {
  return {
    prerender: {
      enabled: true,
      crawlLinks: false,
      filter: ({ path }: { path: string }) => isInitPrerenderPath(path),
      concurrency: 1,
      failOnError: true,
    },
    pages: getInitPrerenderPageEntries(),
  }
}

export default defineConfig(async () => {
  const isSitesBuild = process.env.FOR_SITES === 'true'
  // Source-map upload is a build-time concern, gated only on the auth token.
  // The runtime Sentry DSN is injected via runtime config (see runtime-config.ts).
  const sentryPlugins = process.env.SENTRY_AUTH_TOKEN
    ? [
        (await import('@sentry/tanstackstart-react/vite')).sentryTanstackStart({
          org: 'appwrite',
          project: 'console-v4',
          authToken: process.env.SENTRY_AUTH_TOKEN,
        }),
      ]
    : []

  return {
    base: process.env.CDN_ORIGIN
      ? `${process.env.CDN_ORIGIN.replace(/\/$/, '')}/`
      : '/',
    plugins: [
      silenceAbortedSsrPlugin(),
      // this is the plugin that enables path aliases
      viteTsConfigPaths({
        projects: ['./tsconfig.json'],
      }),
      tailwindcss(),
      tanstackStart({
        router: { basepath: '/' },
        ...(isSitesBuild
          ? getTanstackStartSitesOptions()
          : getTanstackStartCloudOptions()),
      }),
      devtoolsJson(),
      viteReact(),
      docsContentHmrPlugin(),
      rejectEmptyBuildAssetsPlugin(),
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
          find: /^decimal\.js-light$/,
          replacement: decimalJsLightShim,
        },
        {
          find: /^decimal\.js$/,
          replacement: decimalJsShim,
        },
      ],
    },
    optimizeDeps: {
      // Recharts uses decimal.js (via victory-vendor/d3-scale) for tick calculations.
      // Force ESM interop so `new Decimal()` works when pre-bundled.
      needsInterop: [
        'decimal.js',
        'decimal.js-light',
        // Appwrite console SDK default-imports this CJS package from dist/esm/sdk.js.
        'json-bigint',
        // CJS shim entries: named ESM imports fail unless pre-bundled with interop.
        // with-selector: recharts / @tanstack/react-store.
        // shim: @radix-ui/react-use-is-hydrated (pulled in by excluded Avatar).
        'use-sync-external-store/shim',
        'use-sync-external-store/shim/with-selector.js',
      ],
      include: [
        'decimal.js',
        'decimal.js-light',
        'recharts',
        'use-sync-external-store',
        'use-sync-external-store/shim',
        'use-sync-external-store/shim/with-selector.js',
        // Pre-bundle so json-bigint gets a default export shim and the console SDK stays in sync
        // with the installed @appwrite.io/console version. Clear node_modules/.vite after SDK bumps.
        '@appwrite.io/console',
        'json-bigint',
      ],
      // Serve TanStack store packages as native ESM. Pre-bundling cached an older
      // @tanstack/react-store without createAtom when router upgraded first.
      exclude: [
        '@tanstack/react-store',
        '@tanstack/store',
        'sharp',
        // Pre-bundling inlines nested @radix-ui copies and can load a second React
        // instance, breaking hooks (useState of null) in ScrollArea / Avatar.
        '@radix-ui/react-scroll-area',
        '@radix-ui/react-avatar',
      ],
    },
    ssr: {
      // Keep native/heavy packages external; bundle recharts + its Redux chain so
      // Appwrite Sites ModClean cannot strip @reduxjs/toolkit's .mjs files from
      // node_modules (runtime then fails with Cannot find module …modern.mjs).
      external: [
        'sharp',
        'prismjs',
        'three',
        'three-globe',
        '@react-three/fiber',
        '@react-three/drei',
      ],
      noExternal: [
        'recharts',
        '@reduxjs/toolkit',
        'react-redux',
        'immer',
        'reselect',
        'redux',
        'redux-thunk',
      ],
    },
    preview: {
      port: 4173,
      host: '::',
    },
    build: {
      outDir: 'dist',
      sourcemap: isSitesBuild
        ? false
        : process.env.SENTRY_AUTH_TOKEN
          ? 'hidden'
          : false,
    },
  }
})
