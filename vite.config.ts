import path from 'node:path'
import { createRequire } from 'node:module'
import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import { tanstackStart } from '@tanstack/react-start/plugin/vite'
import viteReact from '@vitejs/plugin-react'
import viteTsConfigPaths from 'vite-tsconfig-paths'
import tailwindcss from '@tailwindcss/vite'
import devtoolsJson from 'vite-plugin-devtools-json'
import {
  almostnodeBuildPlugin,
  ensureAlmostnodePatchCache,
} from './src/lib/cli-shell/vite-almostnode-plugin'
import {
  getAllMarketingPrerenderPaths,
  getSitesPrerenderBuildSummary,
  isMarketingPrerenderPath,
} from './src/lib/marketing/marketing-build-paths'
import { getSitesPrerenderConcurrency } from './src/lib/marketing/sites-prerender-scope'

const projectRoot = path.dirname(fileURLToPath(import.meta.url))
const require = createRequire(import.meta.url)
const resolveExportsCjsEntry = require.resolve('resolve.exports')
const almostnodeDist = path.resolve(projectRoot, 'node_modules/almostnode/dist')
const almostnodeCacheDir = path.resolve(projectRoot, '.cache/almostnode')
const almostnodeEntry = ensureAlmostnodePatchCache(
  almostnodeDist,
  almostnodeCacheDir,
).indexEntry
// Avoid import.meta.resolve here: Vite bundles vite.config.ts before loading it,
// and Bun cannot resolve the injected import-meta-resolve shim in that bundle.
const justBashBrowserEntry = path.resolve(
  projectRoot,
  'node_modules/just-bash/dist/bundle/browser.js',
)
const sprintfJsShim = path.resolve(
  projectRoot,
  'src/lib/cli-shell/shims/sprintf-js.ts',
)
const resolveExportsShim = path.resolve(
  projectRoot,
  'src/lib/cli-shell/shims/resolve-exports.ts',
)
const decimalJsLightShim = path.resolve(
  projectRoot,
  'src/lib/shims/decimal-js-light.ts',
)
const decimalJsShim = path.resolve(
  projectRoot,
  'src/lib/shims/decimal-js.ts',
)
const almostnodeSrc = path.resolve(projectRoot, 'node_modules/almostnode/src')

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
      bundle: Record<string, { type: string; fileName: string; code?: string; source?: string | Uint8Array }>,
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
      filter: ({ path }: { path: string }) => isMarketingPrerenderPath(path),
    },
    pages: getAllMarketingPrerenderPaths().map((path) => ({
      path,
      prerender: { enabled: true },
    })),
  }
}

export default defineConfig(async () => {
  const isSitesBuild = process.env.FOR_SITES === 'true'
  // Source-map upload is a build-time concern, gated only on the auth token.
  // The runtime Sentry DSN is injected via runtime config (see runtime-config.ts).
  const sentryPlugins =
    process.env.SENTRY_AUTH_TOKEN
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
      tanstackStart(isSitesBuild ? getTanstackStartSitesOptions() : undefined),
      devtoolsJson(),
      almostnodeBuildPlugin(almostnodeDist, almostnodeCacheDir, {
        projectRoot,
        justBashBrowserEntry,
      }),
      viteReact(),
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
        {
          find: /^sprintf-js$/,
          replacement: sprintfJsShim,
        },
        {
          find: /^resolve\.exports$/,
          replacement: resolveExportsShim,
        },
        {
          find: /^@cli-shell\/cjs\/resolve\.exports$/,
          replacement: resolveExportsCjsEntry,
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
          replacement: almostnodeEntry,
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
        'resolve.exports',
        '@cli-shell/cjs/resolve.exports',
        // Appwrite console SDK default-imports this CJS package from dist/esm/sdk.js.
        'json-bigint',
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
        'almostnode',
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
      external: ['sharp', 'prismjs'],
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
      rolldownOptions: {
        // almostnode uses direct eval for Node vm/module emulation in the CLI shell.
        onwarn(warning, defaultHandler) {
          const sourceId = warning.id?.replace(/\\/g, '/')
          if (warning.code === 'EVAL' && sourceId?.includes('almostnode')) {
            return
          }
          defaultHandler(warning)
        },
      },
    },
  }
})
