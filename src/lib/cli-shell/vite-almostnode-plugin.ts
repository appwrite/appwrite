import fs from 'node:fs'
import { builtinModules, createRequire } from 'node:module'
import path from 'node:path'
import type { Plugin } from 'vite'
import { patchAlmostnodeBundle } from './almostnode-patches'

const RUNTIME_WORKER_PREFIX = 'runtime-worker-'

function findRuntimeWorkerAsset(assetsDir: string): string | null {
  if (!fs.existsSync(assetsDir)) return null

  return (
    fs
      .readdirSync(assetsDir)
      .find(
        (file) =>
          file.startsWith(RUNTIME_WORKER_PREFIX) &&
          file.endsWith('.js') &&
          !file.endsWith('.js.map'),
      ) ?? null
  )
}

function isAlmostnodeRuntimeWorkerPath(urlPath: string): string | null {
  const pathname = urlPath.split('?')[0]?.split('#')[0] ?? ''
  const fileName = path.basename(pathname)
  if (
    fileName.startsWith(RUNTIME_WORKER_PREFIX) &&
    fileName.endsWith('.js') &&
    !fileName.endsWith('.js.map')
  ) {
    return fileName
  }
  return null
}

function stripViteModuleId(id: string): string {
  return id.split('?')[0]?.split('#')[0] ?? id
}

function isAlmostnodeBundledFile(id: string): boolean {
  const normalized = stripViteModuleId(id).replace(/\\/g, '/')
  return (
    normalized.includes('almostnode/dist/') ||
    normalized.includes('.cache/almostnode/')
  )
}

function isAlmostnodeMainEntry(id: string): boolean {
  const normalized = stripViteModuleId(id).replace(/\\/g, '/')
  return (
    normalized.includes('almostnode/dist/index.mjs') ||
    normalized.includes('.cache/almostnode/index.mjs')
  )
}

function isBareModuleId(source: string): boolean {
  return !source.startsWith('.') && !source.startsWith('/') && !source.includes('\0')
}

function isNodeBuiltin(source: string): boolean {
  const name = source.startsWith('node:') ? source.slice(5) : source
  return builtinModules.includes(name)
}

function writeIfChanged(filePath: string, contents: string): void {
  if (fs.existsSync(filePath) && fs.readFileSync(filePath, 'utf8') === contents) {
    return
  }
  fs.mkdirSync(path.dirname(filePath), { recursive: true })
  fs.writeFileSync(filePath, contents)
}

/**
 * Materialize patched almostnode bundles under `.cache/almostnode` so Vite always
 * serves the fixed entry (load/transform hooks alone are not reliable in dev).
 */
export function ensureAlmostnodePatchCache(
  almostnodeDistDir: string,
  cacheDir: string,
): { indexEntry: string; workerFileName: string | null } {
  const indexSrc = path.join(almostnodeDistDir, 'index.mjs')
  const indexDest = path.join(cacheDir, 'index.mjs')
  writeIfChanged(
    indexDest,
    patchAlmostnodeBundle(fs.readFileSync(indexSrc, 'utf8')),
  )

  const assetsDir = path.join(almostnodeDistDir, 'assets')
  const workerFileName = findRuntimeWorkerAsset(assetsDir)
  if (workerFileName) {
    const workerSrc = path.join(assetsDir, workerFileName)
    const workerDest = path.join(cacheDir, 'assets', workerFileName)
    writeIfChanged(
      workerDest,
      patchAlmostnodeBundle(fs.readFileSync(workerSrc, 'utf8')),
    )
  }

  return { indexEntry: indexDest, workerFileName }
}

function readRuntimeWorkerSource(
  assetsDir: string,
  workerFileName: string,
): string {
  return patchAlmostnodeBundle(
    fs.readFileSync(path.join(assetsDir, workerFileName), 'utf8'),
  )
}

type AlmostnodeBuildPluginOptions = {
  projectRoot: string
  justBashBrowserEntry: string
}

/**
 * createRequire picks Node/CJS entry points that break named ESM imports in the
 * browser. Map almostnode's bare imports to browser-safe paths instead.
 */
function buildBrowserDependencyResolutions(
  resolvePkg: (id: string) => string,
  options: AlmostnodeBuildPluginOptions,
): Record<string, string> {
  const comlinkUmd = resolvePkg('comlink')
  const pakoEntry = resolvePkg('pako')
  const brotliEntry = resolvePkg('brotli-wasm')

  return {
    'resolve.exports': path.join(
      options.projectRoot,
      'src/lib/cli-shell/shims/resolve-exports.ts',
    ),
    'just-bash': options.justBashBrowserEntry,
    comlink: path.join(path.dirname(comlinkUmd), '../esm/comlink.mjs'),
    pako: path.join(path.dirname(pakoEntry), 'dist/pako.esm.mjs'),
    'brotli-wasm': path.join(path.dirname(brotliEntry), 'index.web.js'),
  }
}

/**
 * almostnode ships a prebuilt dist that instantiates a worker via
 * `new URL(/* @vite-ignore *\/ "...", import.meta.url)`.
 * rolldown-vite still tries to bundle that worker and fails because the path
 * points at almostnode's own build output. Rewrite the URL to a plain string
 * and emit the worker asset into the app bundle.
 *
 * The patched bundle also keeps bare imports to almostnode's own dependencies
 * (pako, brotli-wasm, etc.). pnpm nests those as siblings in the virtual store,
 * not under the symlinked `node_modules/almostnode/node_modules` tree, so imports
 * from `.cache/almostnode` cannot resolve them without this hook. bun/npm hoist
 * transitive deps to the project root and mask the issue locally.
 */
export function almostnodeBuildPlugin(
  almostnodeDistDir: string,
  cacheDir: string,
  options: AlmostnodeBuildPluginOptions,
): Plugin {
  const assetsDir = path.join(almostnodeDistDir, 'assets')
  const cacheAssetsDir = path.join(cacheDir, 'assets')
  const almostnodeResolveEntry = fs.realpathSync(
    path.join(almostnodeDistDir, 'index.mjs'),
  )
  const resolveAlmostnodeDependency = createRequire(almostnodeResolveEntry).resolve
  const browserDependencyResolutions = buildBrowserDependencyResolutions(
    resolveAlmostnodeDependency,
    options,
  )
  let workerFileName = findRuntimeWorkerAsset(assetsDir)

  return {
    name: 'almostnode-build-fix',
    enforce: 'pre',
    resolveId(source, importer) {
      if (
        !importer ||
        !isBareModuleId(source) ||
        isNodeBuiltin(source) ||
        !isAlmostnodeBundledFile(importer)
      ) {
        return null
      }

      const browserResolution = browserDependencyResolutions[source]
      if (browserResolution) {
        return browserResolution
      }

      try {
        return resolveAlmostnodeDependency(source)
      } catch {
        return null
      }
    },
    buildStart() {
      const cached = ensureAlmostnodePatchCache(almostnodeDistDir, cacheDir)
      workerFileName = cached.workerFileName ?? workerFileName
    },
    load(id) {
      if (!isAlmostnodeMainEntry(id)) return null
      const filePath = stripViteModuleId(id)
      return patchAlmostnodeBundle(fs.readFileSync(filePath, 'utf8'))
    },
    transform(code, id) {
      const normalizedId = stripViteModuleId(id).replace(/\\/g, '/')
      if (
        normalizedId.includes('almostnode/dist/assets/runtime-worker-') ||
        normalizedId.includes('.cache/almostnode/assets/runtime-worker-')
      ) {
        const transformed = patchAlmostnodeBundle(code)
        if (transformed === code) return null
        return { code: transformed, map: null }
      }

      if (!isAlmostnodeMainEntry(id)) return null

      const transformed = patchAlmostnodeBundle(code)
      if (transformed === code) return null
      return { code: transformed, map: null }
    },
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        const workerFile =
          req.url != null ? isAlmostnodeRuntimeWorkerPath(req.url) : null
        if (!workerFile) {
          next()
          return
        }

        const cachedWorkerPath = path.join(cacheAssetsDir, workerFile)
        const workerPath = fs.existsSync(cachedWorkerPath)
          ? cachedWorkerPath
          : path.join(assetsDir, workerFile)
        if (!fs.existsSync(workerPath)) {
          next()
          return
        }

        res.setHeader('Content-Type', 'application/javascript')
        res.setHeader('Cache-Control', 'no-store')
        res.end(readRuntimeWorkerSource(path.dirname(workerPath), workerFile))
      })
    },
    generateBundle() {
      if (!workerFileName) return

      const cachedWorkerPath = path.join(cacheAssetsDir, workerFileName)
      const workerPath = fs.existsSync(cachedWorkerPath)
        ? cachedWorkerPath
        : path.join(assetsDir, workerFileName)
      if (!fs.existsSync(workerPath)) return

      this.emitFile({
        type: 'asset',
        fileName: `assets/${workerFileName}`,
        source: readRuntimeWorkerSource(path.dirname(workerPath), workerFileName),
      })
    },
  }
}
