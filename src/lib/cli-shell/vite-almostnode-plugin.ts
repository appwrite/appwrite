import fs from 'node:fs'
import path from 'node:path'
import type { Plugin } from 'vite'

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

/**
 * almostnode ships a prebuilt dist that instantiates a worker via
 * `new URL(/* @vite-ignore *\/ "...", import.meta.url)`.
 * rolldown-vite still tries to bundle that worker and fails because the path
 * points at almostnode's own build output. Rewrite the URL to a plain string
 * and emit the worker asset into the app bundle.
 */
export function almostnodeBuildPlugin(almostnodeDistDir: string): Plugin {
  const assetsDir = path.join(almostnodeDistDir, 'assets')
  let workerFileName = findRuntimeWorkerAsset(assetsDir)
  let workerPublicPath = workerFileName ? `/assets/${workerFileName}` : null

  return {
    name: 'almostnode-build-fix',
    enforce: 'pre',
    transform(code, id) {
      if (!id.includes('almostnode/dist/index.mjs')) return null

      const transformed = code.replace(
        /new URL\(\s*\/\*\s*@vite-ignore\s*\*\/\s*"([^"]+)",\s*import\.meta\.url\s*\)/gs,
        '"$1"',
      )

      if (transformed === code) return null
      return { code: transformed, map: null }
    },
    configureServer(server) {
      if (!workerFileName || !workerPublicPath) return

      server.middlewares.use(workerPublicPath, (_req, res) => {
        const workerPath = path.join(assetsDir, workerFileName!)
        if (!fs.existsSync(workerPath)) {
          res.statusCode = 404
          res.end('almostnode runtime worker not found')
          return
        }

        res.setHeader('Content-Type', 'application/javascript')
        res.setHeader('Cache-Control', 'no-cache')
        res.end(fs.readFileSync(workerPath))
      })
    },
    generateBundle() {
      if (!workerFileName) return

      const workerPath = path.join(assetsDir, workerFileName)
      if (!fs.existsSync(workerPath)) return

      this.emitFile({
        type: 'asset',
        fileName: `assets/${workerFileName}`,
        source: fs.readFileSync(workerPath),
      })
    },
  }
}
