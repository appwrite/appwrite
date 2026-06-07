import fs from 'node:fs'
import path from 'node:path'
import type { Plugin } from 'vite'
import { patchAlmostnodeRuntime } from './patch-almostnode-runtime'

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

function isAlmostnodeMainEntry(id: string): boolean {
  return id.replace(/\\/g, '/').includes('almostnode/dist/index.mjs')
}

function patchAlmostnodeBundle(code: string): string {
  let next = code.replace(
    /new URL\(\s*\/\*\s*@vite-ignore\s*\*\/\s*"([^"]+)",\s*import\.meta\.url\s*\)/gs,
    '"$1"',
  )
  next = patchAlmostnodeRuntime(next)
  return next
}

function readRuntimeWorkerSource(
  assetsDir: string,
  workerFileName: string,
): string {
  return patchAlmostnodeBundle(
    fs.readFileSync(path.join(assetsDir, workerFileName), 'utf8'),
  )
}

/**
 * almostnode ships a prebuilt dist that instantiates a worker via
 * `new URL(/* @vite-ignore *\/ "...", import.meta.url)`.
 * rolldown-vite still tries to bundle that worker and fails because the path
 * points at almostnode's own build output. Rewrite the URL to a plain string
 * and emit the worker asset into the app bundle.
 *
 * Also patches almostnode runtime shims (stream, readline) for CLI compatibility.
 * See patch-almostnode-runtime.ts.
 */
export function almostnodeBuildPlugin(almostnodeDistDir: string): Plugin {
  const assetsDir = path.join(almostnodeDistDir, 'assets')
  let workerFileName = findRuntimeWorkerAsset(assetsDir)

  return {
    name: 'almostnode-build-fix',
    enforce: 'pre',
    load(id) {
      if (!isAlmostnodeMainEntry(id)) return null
      return patchAlmostnodeBundle(fs.readFileSync(id, 'utf8'))
    },
    transform(code, id) {
      const normalizedId = id.replace(/\\/g, '/')
      if (normalizedId.includes('almostnode/dist/assets/runtime-worker-')) {
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

        const workerPath = path.join(assetsDir, workerFile)
        if (!fs.existsSync(workerPath)) {
          next()
          return
        }

        res.setHeader('Content-Type', 'application/javascript')
        res.setHeader('Cache-Control', 'no-store')
        res.end(readRuntimeWorkerSource(assetsDir, workerFile))
      })
    },
    generateBundle() {
      if (!workerFileName) return

      const workerPath = path.join(assetsDir, workerFileName)
      if (!fs.existsSync(workerPath)) return

      this.emitFile({
        type: 'asset',
        fileName: `assets/${workerFileName}`,
        source: readRuntimeWorkerSource(assetsDir, workerFileName),
      })
    },
  }
}
