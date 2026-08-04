/**
 * TanStack Start Production Server with Bun
 *
 * A high-performance production server for TanStack Start applications that
 * implements intelligent static asset loading with configurable memory management.
 *
 * Features:
 * - Hybrid loading strategy (preload small files, serve large files on-demand)
 * - Configurable file filtering with include/exclude patterns
 * - Memory-efficient response generation
 * - Production-ready caching headers
 *
 * Environment Variables:
 *
 * PORT (number)
 *   - Server port number
 *   - Default: 3000
 *
 * ASSET_PRELOAD_MAX_SIZE (number)
 *   - Maximum file size in bytes to preload into memory
 *   - Files larger than this will be served on-demand from disk
 *   - Default: 5242880 (5MB)
 *   - Example: ASSET_PRELOAD_MAX_SIZE=5242880 (5MB)
 *
 * ASSET_PRELOAD_INCLUDE_PATTERNS (string)
 *   - Comma-separated list of glob patterns for files to include
 *   - If specified, only matching files are eligible for preloading
 *   - Patterns are matched against filenames only, not full paths
 *   - Example: ASSET_PRELOAD_INCLUDE_PATTERNS="*.js,*.css,*.woff2"
 *
 * ASSET_PRELOAD_EXCLUDE_PATTERNS (string)
 *   - Comma-separated list of glob patterns for files to exclude
 *   - Applied after include patterns
 *   - Patterns are matched against filenames only, not full paths
 *   - Example: ASSET_PRELOAD_EXCLUDE_PATTERNS="*.map,*.txt"
 *
 * ASSET_PRELOAD_VERBOSE_LOGGING (boolean)
 *   - Enable detailed logging of loaded and skipped files
 *   - Default: false
 *   - Set to "true" to enable verbose output
 *
 * ASSET_PRELOAD_ENABLE_ETAG (boolean)
 *   - Enable ETag generation for preloaded assets
 *   - Default: true
 *   - Set to "false" to disable ETag support
 *
 * ASSET_PRELOAD_ENABLE_GZIP (boolean)
 *   - Enable Gzip compression for eligible assets
 *   - Default: true
 *   - Set to "false" to disable Gzip compression
 *
 * ASSET_PRELOAD_GZIP_MIN_SIZE (number)
 *   - Minimum file size in bytes required for Gzip compression
 *   - Files smaller than this will not be compressed
 *   - Default: 1024 (1KB)
 *
 * ASSET_PRELOAD_GZIP_MIME_TYPES (string)
 *   - Comma-separated list of MIME types eligible for Gzip compression
 *   - Supports partial matching for types ending with "/"
 *   - Default: text/,application/javascript,application/json,application/xml,image/svg+xml
 *
 * Usage:
 *   bun run server.ts
 */

import path from 'node:path'
// Keep this import first among local modules so Sentry is ready when this file
// is started without `--preload` (preload is still required to catch failures
// in sibling static imports below).
import {
  captureServerException,
  flushSentryServer,
} from './src/lib/sentry/init-server.ts'
import {
  getAllMarketingPrerenderPaths,
  getMarketingPrerenderHtmlFile,
} from './src/lib/marketing/marketing-build-paths.ts'
import { isThreadsRoutePath } from './src/lib/threads/prerender-paths.ts'
import {
  isLegacyConsolePath,
  rewriteLegacyConsolePath,
} from './src/lib/legacy-console-path.ts'
import {
  injectRuntimeConfigIntoHtml,
  readRuntimeConfigFromEnv,
  serializeRuntimeConfig,
  shouldWarnAboutMissingAppwriteEndpoint,
} from './src/lib/runtime-config-shared.ts'
import {
  applyNoIndexResponseHeaders,
  getRequestHostFromHeaders,
  isSeoIndexableHost,
} from './src/lib/seo/indexing.ts'
import {
  AI_CATALOG_CONTENT_TYPE,
  APPWRITE_AI_CATALOG_PATH,
  APPWRITE_AGENT_SKILLS_DISCOVERY_PATH,
  APPWRITE_MCP_SERVER_CARD_PATH,
  DISCOVERY_CORS_HEADERS,
  MCP_SERVER_CARD_CONTENT_TYPE,
  buildAgentSkillsDiscoveryDocument,
  buildAiCatalogDocument,
  buildMcpServerCard,
  serializeDiscoveryJson,
} from './src/lib/seo/agent-discovery.ts'
import { trackServerPageview } from './src/lib/server-analytics.ts'

// Configuration
const SERVER_PORT = Number(process.env.PORT ?? 3000)
const CLIENT_DIRECTORY = './dist/client'
const SERVER_ENTRY_POINT = './dist/server/server.js'

// Public runtime config, read once from the process env (constant per process)
// and stamped into every HTML response in place of the build-time placeholder -
// see src/lib/runtime-config-shared.ts and src/routes/__root.tsx.
const RUNTIME_CONFIG = readRuntimeConfigFromEnv(process.env)
const RUNTIME_CONFIG_JSON = serializeRuntimeConfig(RUNTIME_CONFIG)

function injectRuntimeConfig(html: string): string {
  return injectRuntimeConfigIntoHtml(html, RUNTIME_CONFIG_JSON)
}

function isIndexableRequest(req: Request): boolean {
  return isSeoIndexableHost(getRequestHostFromHeaders(req.headers, req.url))
}

function withSeoIndexingHeaders(req: Request, response: Response): Response {
  if (isIndexableRequest(req)) return response

  const headers = applyNoIndexResponseHeaders(response.headers)
  headers.delete('content-length')
  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  })
}

function discoveryExportResponse(
  req: Request,
  body: string,
  contentType: string,
): Response {
  trackServerPageview(req, { format: 'json' })
  return new Response(body, {
    headers: {
      'Content-Type': contentType,
      'Cache-Control': 'public, max-age=3600',
      ...DISCOVERY_CORS_HEADERS,
    },
  })
}

async function readClientExportOrFallback(
  relativePath: string,
  fallback: () => string,
): Promise<string> {
  const filepath = path.join(CLIENT_DIRECTORY, relativePath)
  try {
    const file = Bun.file(filepath)
    if (await file.exists()) {
      return await file.text()
    }
  } catch {
    // Fall through to runtime generation.
  }
  return fallback()
}

function htmlResponse(
  req: Request,
  html: string,
  headers: Record<string, string>,
): Response {
  return withSeoIndexingHeaders(
    req,
    new Response(injectRuntimeConfig(html), { headers }),
  )
}

// Logging utilities for professional output
const log = {
  info: (message: string) => {
    console.log(`[INFO] ${message}`)
  },
  success: (message: string) => {
    console.log(`[SUCCESS] ${message}`)
  },
  warning: (message: string) => {
    console.log(`[WARNING] ${message}`)
  },
  error: (message: string) => {
    console.log(`[ERROR] ${message}`)
  },
  header: (message: string) => {
    console.log(`\n${message}\n`)
  },
}

if (
  shouldWarnAboutMissingAppwriteEndpoint(
    RUNTIME_CONFIG,
    process.env.APPWRITE_ENDPOINT_SAME_ORIGIN ?? '',
  )
) {
  log.warning(
    'VITE_APPWRITE_ENDPOINT (or APPWRITE_ENDPOINT) is not set; the browser will use the cloud default or the current host for self-hosted.',
  )
}

// Preloading configuration from environment variables
const MAX_PRELOAD_BYTES = Number(
  process.env.ASSET_PRELOAD_MAX_SIZE ?? 5 * 1024 * 1024, // 5MB default
)

// Parse comma-separated include patterns (no defaults)
const INCLUDE_PATTERNS = (process.env.ASSET_PRELOAD_INCLUDE_PATTERNS ?? '')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean)
  .map((pattern: string) => convertGlobToRegExp(pattern))

// Parse comma-separated exclude patterns (no defaults)
const EXCLUDE_PATTERNS = [
  convertGlobToRegExp('*.html'),
  ...(process.env.ASSET_PRELOAD_EXCLUDE_PATTERNS ?? '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
    .map((pattern: string) => convertGlobToRegExp(pattern)),
]

/**
 * llms exports must never be registered as static routes (neither preloaded
 * nor on-demand); requests fall through to the TanStack route, which serves
 * the same prebuilt file with the right Content-Type and records a
 * server-side pageview.
 */
/**
 * Text / markdown / robots / discovery exports owned by TanStack or explicit
 * Bun handlers so they get the correct Content-Type, CORS, and server-side
 * Plausible pageviews. Never register these as plain Bun static routes.
 */
function isServerTrackedExportFile(relativePath: string): boolean {
  const normalized = relativePath.split(/[/\\]/).join('/')
  return (
    normalized === 'llms.txt' ||
    normalized === 'llms-full.txt' ||
    normalized === 'docs/llms.txt' ||
    normalized === 'docs.md' ||
    normalized === 'blog.md' ||
    normalized === 'changelog.md' ||
    normalized === 'integrations.md' ||
    normalized === 'robots.txt' ||
    normalized === '.well-known/mcp/server-card.json' ||
    normalized === '.well-known/ai-catalog.json' ||
    normalized === '.well-known/agent-skills/index.json'
  )
}

// Verbose logging flag
const VERBOSE = process.env.ASSET_PRELOAD_VERBOSE_LOGGING === 'true'

// Optional ETag feature
const ENABLE_ETAG = (process.env.ASSET_PRELOAD_ENABLE_ETAG ?? 'true') === 'true'

// Optional Gzip feature
const ENABLE_GZIP = (process.env.ASSET_PRELOAD_ENABLE_GZIP ?? 'true') === 'true'
const GZIP_MIN_BYTES = Number(process.env.ASSET_PRELOAD_GZIP_MIN_SIZE ?? 1024) // 1KB
const GZIP_TYPES = (
  process.env.ASSET_PRELOAD_GZIP_MIME_TYPES ??
  'text/,application/javascript,application/json,application/xml,image/svg+xml'
)
  .split(',')
  .map((v) => v.trim())
  .filter(Boolean)

/**
 * Convert a simple glob pattern to a regular expression
 * Supports * wildcard for matching any characters
 */
function convertGlobToRegExp(globPattern: string): RegExp {
  // Escape regex special chars except *, then replace * with .*
  const escapedPattern = globPattern
    .replace(/[-/\\^$+?.()|[\]{}]/g, '\\$&')
    .replace(/\*/g, '.*')
  return new RegExp(`^${escapedPattern}$`, 'i')
}

/**
 * Compute ETag for a given data buffer
 */
function computeEtag(data: Uint8Array): string {
  const hash = Bun.hash(data)
  return `W/"${hash.toString(16)}-${data.byteLength.toString()}"`
}

/**
 * Metadata for preloaded static assets
 */
interface AssetMetadata {
  route: string
  size: number
  type: string
}

/**
 * In-memory asset with ETag and Gzip support
 */
interface InMemoryAsset {
  raw: Uint8Array
  gz?: Uint8Array
  etag?: string
  type: string
  immutable: boolean
  size: number
}

/**
 * Result of static asset preloading process
 */
interface PreloadResult {
  routes: Record<string, (req: Request) => Response | Promise<Response>>
  loaded: AssetMetadata[]
  skipped: AssetMetadata[]
}

/**
 * Check if a file is eligible for preloading based on configured patterns
 */
function isFileEligibleForPreloading(relativePath: string): boolean {
  const normalized = relativePath.split(/[/\\]/).join('/')
  const fileName = normalized.split('/').pop() ?? normalized

  if (fileName.endsWith('.html')) {
    return false
  }

  // If include patterns are specified, file must match at least one
  if (INCLUDE_PATTERNS.length > 0) {
    if (!INCLUDE_PATTERNS.some((pattern) => pattern.test(fileName))) {
      return false
    }
  }

  // If exclude patterns are specified, file must not match any
  if (EXCLUDE_PATTERNS.some((pattern) => pattern.test(fileName))) {
    return false
  }

  return true
}

/** Thread pages are SSR-only; skip stale prerender HTML under dist/client/threads. */
function isAccidentalThreadStaticHtml(relativePath: string): boolean {
  const normalized = relativePath.split(/[/\\]/).join('/')
  if (!normalized.startsWith('threads/') || !normalized.endsWith('.html')) {
    return false
  }

  const routePath = `/${normalized.replace(/\.html$/, '')}`
  return isThreadsRoutePath(routePath)
}

/**
 * Check if a MIME type is compressible
 */
function isMimeTypeCompressible(mimeType: string): boolean {
  return GZIP_TYPES.some((type) =>
    type.endsWith('/') ? mimeType.startsWith(type) : mimeType === type,
  )
}

/**
 * Conditionally compress data based on size and MIME type
 */
function compressDataIfAppropriate(
  data: Uint8Array,
  mimeType: string,
): Uint8Array | undefined {
  if (!ENABLE_GZIP) return undefined
  if (data.byteLength < GZIP_MIN_BYTES) return undefined
  if (!isMimeTypeCompressible(mimeType)) return undefined
  try {
    return Bun.gzipSync(data.buffer as ArrayBuffer)
  } catch {
    return undefined
  }
}

/**
 * Create response handler function with ETag and Gzip support
 */
function createResponseHandler(
  asset: InMemoryAsset,
): (req: Request) => Response {
  return (req: Request) => {
    const headers: Record<string, string> = {
      'Content-Type': asset.type,
      'Cache-Control': asset.immutable
        ? 'public, max-age=31536000, immutable'
        : 'public, max-age=3600',
    }

    if (ENABLE_ETAG && asset.etag) {
      const ifNone = req.headers.get('if-none-match')
      if (ifNone && ifNone === asset.etag) {
        return new Response(null, {
          status: 304,
          headers: { ETag: asset.etag },
        })
      }
      headers.ETag = asset.etag
    }

    if (
      ENABLE_GZIP &&
      asset.gz &&
      req.headers.get('accept-encoding')?.includes('gzip')
    ) {
      headers['Content-Encoding'] = 'gzip'
      headers['Content-Length'] = String(asset.gz.byteLength)
      const gzCopy = new Uint8Array(asset.gz)
      return new Response(gzCopy, { status: 200, headers })
    }

    headers['Content-Length'] = String(asset.raw.byteLength)
    const rawCopy = new Uint8Array(asset.raw)
    return new Response(rawCopy, { status: 200, headers })
  }
}

/**
 * Create composite glob pattern from include patterns
 */
function createCompositeGlobPattern(): Bun.Glob {
  const raw = (process.env.ASSET_PRELOAD_INCLUDE_PATTERNS ?? '')
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
  if (raw.length === 0) return new Bun.Glob('**/*')
  if (raw.length === 1) return new Bun.Glob(raw[0])
  return new Bun.Glob(`{${raw.join(',')}}`)
}

/**
 * Initialize static routes with intelligent preloading strategy
 * Small files are loaded into memory, large files are served on-demand
 */
async function initializeStaticRoutes(
  clientDirectory: string,
): Promise<PreloadResult> {
  const routes: Record<string, (req: Request) => Response | Promise<Response>> =
    {}
  const loaded: AssetMetadata[] = []
  const skipped: AssetMetadata[] = []

  log.info(`Loading static assets from ${clientDirectory}...`)
  if (VERBOSE) {
    console.log(
      `Max preload size: ${(MAX_PRELOAD_BYTES / 1024 / 1024).toFixed(2)} MB`,
    )
    if (INCLUDE_PATTERNS.length > 0) {
      console.log(
        `Include patterns: ${process.env.ASSET_PRELOAD_INCLUDE_PATTERNS ?? ''}`,
      )
    }
    if (EXCLUDE_PATTERNS.length > 0) {
      console.log(
        `Exclude patterns: ${process.env.ASSET_PRELOAD_EXCLUDE_PATTERNS ?? ''}`,
      )
    }
  }

  let totalPreloadedBytes = 0

  try {
    const glob = createCompositeGlobPattern()
    for await (const relativePath of glob.scan({ cwd: clientDirectory })) {
      if (isAccidentalThreadStaticHtml(relativePath)) {
        continue
      }
      if (isServerTrackedExportFile(relativePath)) {
        continue
      }

      const filepath = path.join(clientDirectory, relativePath)
      const route = `/${relativePath.split(path.sep).join(path.posix.sep)}`

      try {
        // Get file metadata
        const file = Bun.file(filepath)

        // Skip missing files. Empty JS/CSS chunks can still be valid ES module
        // side-effect imports after tree-shaking (Rolldown sometimes emits a
        // 0-byte hashed file that other chunks import). Skipping them makes the
        // SPA fallback return HTML for /assets/*.js → MIME type errors in prod.
        if (!(await file.exists())) {
          continue
        }
        const isEmptyModuleAsset =
          file.size === 0 &&
          /\.(?:m?js|cjs|css|wasm)$/i.test(relativePath)
        if (file.size === 0 && !isEmptyModuleAsset) {
          continue
        }
        if (isEmptyModuleAsset) {
          log.warn(
            `Serving empty build asset ${route} (${file.size} bytes). Prefer fixing the empty chunk in the bundle.`,
          )
        }

        const metadata: AssetMetadata = {
          route,
          size: file.size,
          type: file.type || 'application/octet-stream',
        }

        // Determine if file should be preloaded
        const matchesPattern = isFileEligibleForPreloading(relativePath)
        const withinSizeLimit = file.size <= MAX_PRELOAD_BYTES

        if (matchesPattern && withinSizeLimit) {
          // Preload small files into memory with ETag and Gzip support
          const bytes = new Uint8Array(await file.arrayBuffer())
          const gz = compressDataIfAppropriate(bytes, metadata.type)
          const etag = ENABLE_ETAG ? computeEtag(bytes) : undefined
          const asset: InMemoryAsset = {
            raw: bytes,
            gz,
            etag,
            type: metadata.type,
            immutable: true,
            size: bytes.byteLength,
          }
          routes[route] = createResponseHandler(asset)

          loaded.push({ ...metadata, size: bytes.byteLength })
          totalPreloadedBytes += bytes.byteLength
        } else {
          // Serve large or filtered files on-demand. HTML documents get the
          // runtime config stamped in (prerendered pages would otherwise carry
          // build-time config frozen into window.__APP_CONFIG__).
          routes[route] = async (req: Request) => {
            if (metadata.type.includes('text/html')) {
              // Never cache HTML: it embeds hashed asset URLs. Caching across
              // deploys leaves tabs on a shell that 404s deleted /assets/*.js.
              return htmlResponse(req, await Bun.file(filepath).text(), {
                'Content-Type': metadata.type,
                'Cache-Control': 'no-store',
              })
            }
            const fileOnDemand = Bun.file(filepath)
            return withSeoIndexingHeaders(
              req,
              new Response(fileOnDemand, {
                headers: {
                  'Content-Type': metadata.type,
                  'Cache-Control': 'public, max-age=3600',
                },
              }),
            )
          }

          skipped.push(metadata)
        }
      } catch (error: unknown) {
        if (error instanceof Error && error.name !== 'EISDIR') {
          log.error(`Failed to load ${filepath}: ${error.message}`)
        }
      }
    }

    // Serve prerendered marketing HTML at clean URLs (/home, not /home.html).
    for (const urlPath of getAllMarketingPrerenderPaths(CLIENT_DIRECTORY)) {
      const htmlFile = getMarketingPrerenderHtmlFile(urlPath)
      if (!htmlFile) continue

      const filepath = path.join(clientDirectory, htmlFile)
      const file = Bun.file(filepath)
      if (!(await file.exists())) continue

      routes[urlPath] = async (req: Request) =>
        htmlResponse(req, await Bun.file(filepath).text(), {
          'Content-Type': 'text/html; charset=utf-8',
          // Same as SSR HTML: hashed script URLs must not outlive a deploy.
          'Cache-Control': 'no-store',
        })

      skipped.push({
        route: urlPath,
        size: file.size,
        type: 'text/html; charset=utf-8',
      })
    }

    // Show detailed file overview only when verbose mode is enabled
    if (VERBOSE && (loaded.length > 0 || skipped.length > 0)) {
      const allFiles = [...loaded, ...skipped].sort((a, b) =>
        a.route.localeCompare(b.route),
      )

      // Calculate max path length for alignment
      const maxPathLength = Math.min(
        Math.max(...allFiles.map((f) => f.route.length)),
        60,
      )

      // Format file size with KB and actual gzip size
      const formatFileSize = (bytes: number, gzBytes?: number) => {
        const kb = bytes / 1000
        const sizeStr = kb < 100 ? kb.toFixed(2) : kb.toFixed(1)

        if (gzBytes !== undefined) {
          const gzKb = gzBytes / 1000
          const gzStr = gzKb < 100 ? gzKb.toFixed(2) : gzKb.toFixed(1)
          return {
            size: sizeStr,
            gzip: gzStr,
          }
        }

        // Rough gzip estimation (typically 30-70% compression) if no actual gzip data
        const gzipKb = kb * 0.35
        return {
          size: sizeStr,
          gzip: gzipKb < 100 ? gzipKb.toFixed(2) : gzipKb.toFixed(1),
        }
      }

      if (loaded.length > 0) {
        console.log('\n📁 Preloaded into memory:')
        console.log(
          'Path                                          │    Size │ Gzip Size',
        )
        loaded
          .sort((a, b) => a.route.localeCompare(b.route))
          .forEach((file) => {
            const { size, gzip } = formatFileSize(file.size)
            const paddedPath = file.route.padEnd(maxPathLength)
            const sizeStr = `${size.padStart(7)} kB`
            const gzipStr = `${gzip.padStart(7)} kB`
            console.log(`${paddedPath} │ ${sizeStr} │  ${gzipStr}`)
          })
      }

      if (skipped.length > 0) {
        console.log('\n💾 Served on-demand:')
        console.log(
          'Path                                          │    Size │ Gzip Size',
        )
        skipped
          .sort((a, b) => a.route.localeCompare(b.route))
          .forEach((file) => {
            const { size, gzip } = formatFileSize(file.size)
            const paddedPath = file.route.padEnd(maxPathLength)
            const sizeStr = `${size.padStart(7)} kB`
            const gzipStr = `${gzip.padStart(7)} kB`
            console.log(`${paddedPath} │ ${sizeStr} │  ${gzipStr}`)
          })
      }
    }

    // Show detailed verbose info if enabled
    if (VERBOSE) {
      if (loaded.length > 0 || skipped.length > 0) {
        const allFiles = [...loaded, ...skipped].sort((a, b) =>
          a.route.localeCompare(b.route),
        )
        console.log('\n📊 Detailed file information:')
        console.log(
          'Status       │ Path                            │ MIME Type                    │ Reason',
        )
        allFiles.forEach((file) => {
          const isPreloaded = loaded.includes(file)
          const status = isPreloaded ? 'MEMORY' : 'ON-DEMAND'
          const reason =
            !isPreloaded && file.size > MAX_PRELOAD_BYTES
              ? 'too large'
              : !isPreloaded
                ? 'filtered'
                : 'preloaded'
          const route =
            file.route.length > 30
              ? file.route.substring(0, 27) + '...'
              : file.route
          console.log(
            `${status.padEnd(12)} │ ${route.padEnd(30)} │ ${file.type.padEnd(28)} │ ${reason.padEnd(10)}`,
          )
        })
      } else {
        console.log('\n📊 No files found to display')
      }
    }

    // Log summary after the file list
    console.log() // Empty line for separation
    if (loaded.length > 0) {
      log.success(
        `Preloaded ${String(loaded.length)} files (${(totalPreloadedBytes / 1024 / 1024).toFixed(2)} MB) into memory`,
      )
    } else {
      log.info('No files preloaded into memory')
    }

    if (skipped.length > 0) {
      const tooLarge = skipped.filter((f) => f.size > MAX_PRELOAD_BYTES).length
      const filtered = skipped.length - tooLarge
      log.info(
        `${String(skipped.length)} files will be served on-demand (${String(tooLarge)} too large, ${String(filtered)} filtered)`,
      )
    }
  } catch (error) {
    log.error(
      `Failed to load static files from ${clientDirectory}: ${String(error)}`,
    )
    captureServerException(error, {
      source: 'static-routes-init',
      clientDirectory,
    })
  }

  return { routes, loaded, skipped }
}

/** Redirect pre-2.0 `/console/...` and typed-resource deep links to vibes routes. */
function redirectLegacyConsolePath(req: Request): Response {
  const url = new URL(req.url)
  const location = rewriteLegacyConsolePath(url.pathname) + url.search
  return new Response(null, {
    status: 302,
    headers: {
      Location: location,
      'Cache-Control': 'no-store',
    },
  })
}

function internalServerErrorResponse(): Response {
  const body = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Appwrite Console</title>
<link rel="icon" href="/logo.svg" type="image/svg+xml"/>
<link rel="shortcut icon" href="/favicon.ico"/>
</head>
<body style="font-family:system-ui,sans-serif;margin:2rem">
<p>Internal Server Error</p>
</body>
</html>`
  return new Response(body, {
    status: 500,
    headers: { 'Content-Type': 'text/html; charset=utf-8' },
  })
}

/**
 * Initialize the server
 */
async function initializeServer() {
  log.header('Starting Production Server')

  // Load TanStack Start server handler
  let handler: { fetch: (request: Request) => Response | Promise<Response> }
  try {
    const serverModule = (await import(SERVER_ENTRY_POINT)) as {
      default: { fetch: (request: Request) => Response | Promise<Response> }
    }
    handler = serverModule.default
    log.success('TanStack Start application handler initialized')
  } catch (error) {
    log.error(`Failed to load server handler: ${String(error)}`)
    captureServerException(error, { source: 'server-handler-load' })
    await flushSentryServer()
    process.exit(1)
  }

  // Build static routes with intelligent preloading
  const { routes: staticRoutes } = await initializeStaticRoutes(CLIENT_DIRECTORY)

  // Create Bun server
  const server = Bun.serve({
    port: SERVER_PORT,
    hostname: '::',
    idleTimeout: 0,
    routes: {
      // Liveness/readiness probe
      '/health': () =>
        new Response('OK', {
          status: 200,
          headers: {
            'Content-Type': 'text/plain; charset=utf-8',
            'Cache-Control': 'no-store',
          },
        }),

      // Legacy console URLs (pre-2.0 served the console under /console)
      '/console': redirectLegacyConsolePath,
      '/console/*': redirectLegacyConsolePath,

      // MCP / agent discovery (SEP-1649 Server Card + AI Catalog). Excluded from
      // static registration so we can attach CORS + server-side pageviews.
      [APPWRITE_MCP_SERVER_CARD_PATH]: async (req: Request) =>
        discoveryExportResponse(
          req,
          await readClientExportOrFallback(
            '.well-known/mcp/server-card.json',
            () => serializeDiscoveryJson(buildMcpServerCard()),
          ),
          MCP_SERVER_CARD_CONTENT_TYPE,
        ),
      [APPWRITE_AI_CATALOG_PATH]: async (req: Request) =>
        discoveryExportResponse(
          req,
          await readClientExportOrFallback(
            '.well-known/ai-catalog.json',
            () => serializeDiscoveryJson(buildAiCatalogDocument()),
          ),
          AI_CATALOG_CONTENT_TYPE,
        ),
      [APPWRITE_AGENT_SKILLS_DISCOVERY_PATH]: async (req: Request) =>
        discoveryExportResponse(
          req,
          await readClientExportOrFallback(
            '.well-known/agent-skills/index.json',
            () => serializeDiscoveryJson(buildAgentSkillsDiscoveryDocument()),
          ),
          'application/json; charset=utf-8',
        ),

      // Serve static assets (preloaded or on-demand). robots.txt, llms exports,
      // and discovery documents are excluded so they use tracked handlers above
      // or fall through to TanStack.
      ...staticRoutes,

      // Fallback to TanStack Start handler for all other routes. HTML responses
      // get the runtime config stamped in (the SSR shell emits a placeholder).
      '/*': async (req: Request) => {
        try {
          const url = new URL(req.url)
          // Missing hashed build assets must not fall through to the SPA HTML
          // shell. Browsers reject HTML as a module script (MIME type error),
          // and the client stale-chunk reload would otherwise loop forever.
          if (url.pathname.startsWith('/assets/')) {
            return new Response('Not Found', {
              status: 404,
              headers: {
                'Content-Type': 'text/plain; charset=utf-8',
                'Cache-Control': 'no-store',
              },
            })
          }

          // Incomplete strip-only redirects may land on /project-{region}-{id}/...
          // without the /console prefix - rewrite those before the SPA.
          if (isLegacyConsolePath(url.pathname)) {
            return redirectLegacyConsolePath(req)
          }

          const res = await handler.fetch(req)
          const contentType = res.headers.get('content-type') ?? ''
          if (!contentType.includes('text/html')) {
            return withSeoIndexingHeaders(req, res)
          }
          const html = await res.text()
          return htmlResponse(req, html, {
            'Content-Type': contentType,
            'Cache-Control': res.headers.get('cache-control') ?? 'no-store',
          })
        } catch (error) {
          log.error(`Server handler error: ${String(error)}`)
          captureServerException(error, {
            source: 'server-handler-fetch',
            url: req.url,
            method: req.method,
          })
          return internalServerErrorResponse()
        }
      },
    },

    // Global error handler
    error(error) {
      log.error(
        `Uncaught server error: ${error instanceof Error ? error.message : String(error)}`,
      )
      captureServerException(error, { source: 'bun-serve-error' })
      return internalServerErrorResponse()
    },
  })

  log.success(`Server listening on http://localhost:${String(server.port)}`)
}

// Initialize the server
initializeServer().catch(async (error: unknown) => {
  log.error(`Failed to start server: ${String(error)}`)
  captureServerException(error, { source: 'server-initialize' })
  await flushSentryServer()
  process.exit(1)
})
