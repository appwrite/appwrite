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
  CLI_APPWRITE_BIN,
  CLI_APPWRITE_CLI_DIST_TAG,
  CLI_APPWRITE_CLI_PACKAGE,
} from './constants'
import { resolveDependencies, type ResolvedPackage } from './npm-resolver'

const LEGACY_BIN_MARKERS = ['__appwrite_cli_entry', '__cli_global_shim'] as const

/** Marker written into cli.cjs so we only patch the async entry once. */
const CLI_EXIT_CATCH_MARKER = '__appwrite_cli_exit_catch__'

/**
 * almostnode makes `process.exit` throw so Commander stops. The Appwrite CLI
 * boots with `void (async () => { ... })()`, so that throw becomes an unhandled
 * rejection (Vite/Chrome still log it even when preventDefault runs). Attach a
 * .catch that swallows exit errors.
 */
const CLI_EXIT_CATCH_HANDLER = `.catch((err) => {
  /* ${CLI_EXIT_CATCH_MARKER} */
  if (err && typeof err.message === "string" && err.message.startsWith("Process exited with code")) {
    return;
  }
  console.error(err);
})`

export function resolveAppwriteCliMainPath(cwd = '/'): string {
  return path.join(cwd, 'node_modules', CLI_APPWRITE_CLI_PACKAGE, 'dist/cli.cjs')
}

export function isAppwriteCliPackageInstalled(
  vfs: CliShellContainer['vfs'],
): boolean {
  const mainPath = resolveAppwriteCliMainPath()
  if (!vfs.existsSync(mainPath)) return false

  try {
    const stat = vfs.statSync(mainPath)
    return stat.isFile() && stat.size > 0
  } catch {
    return false
  }
}

function isStaleAppwriteBinStub(vfs: CliShellContainer['vfs']): boolean {
  if (!vfs.existsSync(CLI_APPWRITE_BIN)) return true

  try {
    const content = vfs.readFileSync(CLI_APPWRITE_BIN, 'utf8')
    return LEGACY_BIN_MARKERS.some((marker) => content.includes(marker))
  } catch {
    return true
  }
}

/** Rewrite the npm bin stub so it runs appwrite-cli directly (not legacy shims). */
export function ensureAppwriteBinStub(vfs: CliShellContainer['vfs']): boolean {
  const mainPath = resolveAppwriteCliMainPath()
  if (!vfs.existsSync(mainPath)) return false

  if (!isStaleAppwriteBinStub(vfs)) {
    try {
      const stat = vfs.statSync(CLI_APPWRITE_BIN)
      if (stat.isFile() && stat.size > 0) {
        return true
      }
    } catch {
      /* rewrite below */
    }
  }

  const binDir = path.dirname(CLI_APPWRITE_BIN)
  vfs.mkdirSync(binDir, { recursive: true })
  vfs.writeFileSync(
    CLI_APPWRITE_BIN,
    `node "${mainPath}" "$@"\n`,
  )
  return true
}

/**
 * Patch appwrite-cli's top-level async IIFEs so `process.exit` throws from the
 * browser runtime do not surface as uncaught promise rejections.
 */
export function ensureAppwriteCliExitHandling(
  vfs: CliShellContainer['vfs'],
): boolean {
  const mainPath = resolveAppwriteCliMainPath()
  if (!vfs.existsSync(mainPath)) return false

  let source: string
  try {
    source = vfs.readFileSync(mainPath, 'utf8')
  } catch {
    return false
  }

  if (source.includes(CLI_EXIT_CATCH_MARKER)) {
    return true
  }

  if (!source.includes('void (async () =>')) {
    return false
  }

  let patched = source
    .replace(
      /process\.exit\(0\);\s*\}\)\(\);/g,
      `process.exit(0);\n  })()${CLI_EXIT_CATCH_HANDLER};`,
    )
    .replace(
      /process\.stdout\.columns = oldWidth;\s*\}\)\(\);/g,
      `process.stdout.columns = oldWidth;\n  })()${CLI_EXIT_CATCH_HANDLER};`,
    )

  if (patched === source || !patched.includes(CLI_EXIT_CATCH_MARKER)) {
    return false
  }

  vfs.writeFileSync(mainPath, patched)
  return true
}

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

export async function resolveAppwriteCliVersion(
  distTag: string = CLI_APPWRITE_CLI_DIST_TAG,
): Promise<string> {
  const registry = new Registry()
  const manifest = await registry.getPackageManifest(CLI_APPWRITE_CLI_PACKAGE)
  const version =
    manifest['dist-tags']?.[distTag] ?? manifest['dist-tags']?.latest

  if (!version) {
    throw new Error(
      `Could not resolve ${CLI_APPWRITE_CLI_PACKAGE}@${distTag} from npm.`,
    )
  }

  return version
}

export async function installAppwriteCliPackage(
  vfs: CliShellContainer['vfs'],
  onProgress?: (message: string) => void,
  cwd = '/',
  versionRange: string = CLI_APPWRITE_CLI_DIST_TAG,
): Promise<string> {
  const registry = new Registry()

  onProgress?.(
    `Resolving ${CLI_APPWRITE_CLI_PACKAGE}@${versionRange}...`,
  )

  const resolved = await resolveDependencies(
    CLI_APPWRITE_CLI_PACKAGE,
    versionRange,
    { registry, onProgress },
  )

  await installResolvedPackages(vfs, cwd, resolved, onProgress)

  const installedVersion = resolved.get(CLI_APPWRITE_CLI_PACKAGE)?.version
  if (!installedVersion) {
    throw new Error(`${CLI_APPWRITE_CLI_PACKAGE} was not installed.`)
  }

  return installedVersion
}
