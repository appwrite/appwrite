/**
 * Copies @appwrite.io/specs data into dist for production runtimes that only
 * ship the Vite build output (e.g. Appwrite Sites) without node_modules.
 *
 * IMPORTANT: Must live beside dist/server/, not inside it. Appwrite Sites detects
 * the SSR adapter by inspecting dist/server/; tens of thousands of static spec
 * files there trigger "Adapter mismatch: static vs ssr".
 *
 * Run: bun run scripts/copy-appwrite-specs.ts
 */
import { cp, mkdir } from 'node:fs/promises'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = join(__dirname, '..')
const require = createRequire(import.meta.url)
const specsPackageRoot = dirname(require.resolve('@appwrite.io/specs/package.json'))
const destRoot = join(ROOT, 'dist', 'appwrite-specs')

async function run() {
  await mkdir(destRoot, { recursive: true })
  await cp(join(specsPackageRoot, 'specs'), join(destRoot, 'specs'), {
    recursive: true,
  })
  await cp(join(specsPackageRoot, 'examples'), join(destRoot, 'examples'), {
    recursive: true,
  })
  console.log(`Copied @appwrite.io/specs to ${destRoot}`)
}

await run()
