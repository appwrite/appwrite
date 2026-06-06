import { Registry } from '@almostnode-internal/registry'
import { downloadAndExtract } from '@almostnode-internal/tarball'
import {
  initTransformer,
  isTransformerReady,
  transformPackage,
} from '@almostnode-internal/transform'
import * as path from '@almostnode-internal/path'
import type { CliShellContainer } from './types'
import {
  CLI_APPWRITE_CLI_PACKAGE,
  CLI_APPWRITE_CLI_VERSION,
} from './constants'
import { resolveDependencies, type ResolvedPackage } from './npm-resolver'

function normalizeBin(
  pkgName: string,
  bin?: Record<string, string> | string,
): Record<string, string> {
  if (!bin) return {}
  if (typeof bin === 'string') {
    const cmdName = pkgName.includes('/') ? pkgName.split('/').pop()! : pkgName
    return { [cmdName]: bin }
  }
  return bin
}

async function installResolvedPackages(
  vfs: CliShellContainer['vfs'],
  cwd: string,
  resolved: Map<string, ResolvedPackage>,
  onProgress?: (message: string) => void,
): Promise<void> {
  const nodeModulesPath = path.join(cwd, 'node_modules')
  vfs.mkdirSync(nodeModulesPath, { recursive: true })

  const toInstall: Array<{ name: string; pkg: ResolvedPackage; pkgPath: string }> =
    []

  for (const [name, pkg] of resolved) {
    const pkgPath = path.join(nodeModulesPath, name)
    const existingPkgJson = path.join(pkgPath, 'package.json')

    if (vfs.existsSync(existingPkgJson)) {
      try {
        const existing = JSON.parse(vfs.readFileSync(existingPkgJson, 'utf8'))
        if (existing.version === pkg.version) {
          onProgress?.(`Skipping ${name}@${pkg.version} (already installed)`)
          continue
        }
      } catch {
        /* reinstall */
      }
    }

    toInstall.push({ name, pkg, pkgPath })
  }

  if (!isTransformerReady()) {
    onProgress?.('Initializing ESM transformer...')
    await initTransformer()
  }

  const CONCURRENCY = 6
  onProgress?.(`Installing ${toInstall.length} packages...`)

  for (let i = 0; i < toInstall.length; i += CONCURRENCY) {
    const batch = toInstall.slice(i, i + CONCURRENCY)

    await Promise.all(
      batch.map(async ({ name, pkg, pkgPath }) => {
        onProgress?.(`  Downloading ${name}@${pkg.version}...`)

        await downloadAndExtract(pkg.tarballUrl, vfs, pkgPath, {
          stripComponents: 1,
        })

        try {
          const count = await transformPackage(vfs, pkgPath, onProgress)
          if (count > 0) {
            onProgress?.(`  Transformed ${count} files in ${name}`)
          }
        } catch (transformError) {
          onProgress?.(
            `  Warning: Transform failed for ${name}: ${transformError}`,
          )
        }

        try {
          const pkgJsonPath = path.join(pkgPath, 'package.json')
          if (vfs.existsSync(pkgJsonPath)) {
            const pkgJson = JSON.parse(vfs.readFileSync(pkgJsonPath, 'utf8'))
            const binEntries = normalizeBin(name, pkgJson.bin)
            const binDir = path.join(nodeModulesPath, '.bin')
            for (const [cmdName, entryPath] of Object.entries(binEntries)) {
              vfs.mkdirSync(binDir, { recursive: true })
              const targetPath = path.join(pkgPath, entryPath)
              vfs.writeFileSync(
                path.join(binDir, cmdName),
                `node "${targetPath}" "$@"\n`,
              )
            }
          }
        } catch {
          /* non-critical */
        }
      }),
    )
  }

  onProgress?.(`Installed ${resolved.size} packages`)
}

export async function installAppwriteCliPackage(
  vfs: CliShellContainer['vfs'],
  onProgress?: (message: string) => void,
  cwd = '/',
): Promise<void> {
  const registry = new Registry()

  onProgress?.(
    `Resolving ${CLI_APPWRITE_CLI_PACKAGE}@${CLI_APPWRITE_CLI_VERSION}...`,
  )

  const resolved = await resolveDependencies(
    CLI_APPWRITE_CLI_PACKAGE,
    CLI_APPWRITE_CLI_VERSION,
    { registry, onProgress },
  )

  await installResolvedPackages(vfs, cwd, resolved, onProgress)
}
