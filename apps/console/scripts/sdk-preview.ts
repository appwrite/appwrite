/**
 * Build a preview Console SDK: this branch's console spec laid over the spec the
 * pinned @appwrite.io/console was generated from (its `spec.json`). Cloud-only
 * services stay and this branch's new or changed endpoints appear, so Console
 * code for an unreleased endpoint type-checks and builds.
 *
 * Objects merge key by key and this branch wins on values both define. Lists merge
 * by identity (a parameter's name and location, a $ref, a tag or enum value's name),
 * so Cloud-only parameters, properties and enum values survive. An operation this
 * branch moved to another path or method replaces the pinned one; removals are not carried.
 *
 * Runs the Appwrite CLI in the `appwrite` Compose service's image (`appwrite-dev`):
 * Appwrite boots only with the utopia extension, which a host PHP does not load.
 * Build the image first (`docker compose build appwrite` at the repository root); no
 * service needs to run.
 *
 * Usage:
 *   bun run sdk:preview               build and install it into node_modules
 *   bun run sdk:preview --no-install  only build it, in app/sdks/console-web
 *
 * `bun install --force` restores the pinned SDK.
 */
import { cpSync, existsSync, readFileSync, rmSync, writeFileSync } from 'fs'
import { join } from 'path'

const ROOT = join(import.meta.dirname, '..')
const REPOSITORY = join(ROOT, '..', '..')
const PINNED = join(ROOT, 'node_modules', '@appwrite.io', 'console')
const SPECS = join(REPOSITORY, 'app', 'config', 'specs')
const SDK = join(REPOSITORY, 'app', 'sdks', 'console-web')
const VERSION = 'preview'

type Spec = {
  info?: unknown
  servers?: unknown
  paths?: Record<string, unknown>
  components?: { schemas?: Record<string, unknown> }
}

const isObject = (value: unknown): value is Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value)

const identity = (item: unknown): string => {
  if (!isObject(item)) {
    return JSON.stringify(item)
  }
  if (typeof item.$ref === 'string') {
    return `ref:${item.$ref}`
  }
  if (typeof item.name === 'string') {
    return `name:${item.in ?? ''}:${item.name}`
  }
  if (typeof item.title === 'string') {
    return `title:${item.title}`
  }
  return `keys:${Object.keys(item).sort().join(',')}`
}

// Items sharing an identity, like one SDK method's per-platform variants, pair up in order.
const keys = (list: unknown[]): string[] => {
  const seen = new Map<string, number>()
  return list.map((item) => {
    const key = identity(item)
    const occurrence = seen.get(key) ?? 0
    seen.set(key, occurrence + 1)
    return `${key}#${occurrence}`
  })
}

const mergeList = (base: unknown[], overlay: unknown[]): unknown[] => {
  const merged = [...base]
  const positions = new Map(keys(base).map((key, index) => [key, index]))

  keys(overlay).forEach((key, index) => {
    const position = positions.get(key)
    if (position === undefined) {
      positions.set(key, merged.length)
      merged.push(overlay[index])
    } else {
      merged[position] = merge(merged[position], overlay[index])
    }
  })

  return merged
}

const merge = (base: unknown, overlay: unknown): unknown => {
  if (Array.isArray(base) && Array.isArray(overlay)) {
    return mergeList(base, overlay)
  }
  if (isObject(base) && isObject(overlay)) {
    const merged = { ...base }
    for (const [key, value] of Object.entries(overlay)) {
      merged[key] = key in base ? merge(base[key], value) : value
    }
    return merged
  }
  return overlay
}

const operations = (spec: Spec): Map<string, string> => {
  const found = new Map<string, string>()
  for (const [path, item] of Object.entries(spec.paths ?? {})) {
    if (!isObject(item)) {
      continue
    }
    for (const [method, operation] of Object.entries(item)) {
      if (isObject(operation) && typeof operation.operationId === 'string') {
        found.set(operation.operationId, `${path} ${method}`)
      }
    }
  }
  return found
}

// An operation this branch moved to another path or HTTP method replaces the pinned one,
// or the SDK would generate its method twice.
const withoutMoved = (base: Spec, overlay: Spec): Spec => {
  const moved = operations(overlay)
  const paths: Record<string, unknown> = {}
  for (const [path, item] of Object.entries(base.paths ?? {})) {
    if (!isObject(item)) {
      paths[path] = item
      continue
    }
    paths[path] = Object.fromEntries(
      Object.entries(item).filter(([method, operation]) => {
        if (!isObject(operation) || typeof operation.operationId !== 'string') {
          return true
        }
        const location = moved.get(operation.operationId)
        return location === undefined || location === `${path} ${method}`
      }),
    )
  }
  return { ...base, paths }
}

const mergeSpecs = (base: Spec, overlay: Spec): Spec => ({
  ...(merge(withoutMoved(base, overlay), overlay) as Spec),
  info: base.info,
  servers: base.servers,
})

const count = (spec: Spec) =>
  `${Object.keys(spec.paths ?? {}).length} paths, ${Object.keys(spec.components?.schemas ?? {}).length} schemas`

const run = (command: string[], cwd: string) => {
  const result = Bun.spawnSync(command, {
    cwd,
    env: { ...process.env, _APP_SDK_PREVIEW: 'enabled' },
    stdout: 'inherit',
    stderr: 'inherit',
  })
  if (result.exitCode !== 0) {
    throw new Error(`${command.join(' ')} exited with ${result.exitCode}`)
  }
}

// The CLI in a throwaway container of the `appwrite` service's image, as this user, so
// the specs and SDK it writes stay writable here. Only the directories it reads or
// writes are mounted, with no network and no Docker socket: this runs a branch's code.
const MOUNTS = ['app', 'src', 'packages', 'docs', 'public']

const cli = (args: string[]) =>
  run(
    [
      'docker',
      'run',
      '--rm',
      '--network=none',
      `--user=${process.getuid?.()}:${process.getgid?.()}`,
      '--env-file=.env',
      '--env=_APP_SDK_PREVIEW=enabled',
      ...MOUNTS.map(
        (path) => `--volume=${join(REPOSITORY, path)}:/usr/src/code/${path}`,
      ),
      '--entrypoint=php',
      'appwrite-dev',
      'app/cli.php',
      ...args,
    ],
    REPOSITORY,
  )

const readSpec = (path: string): Spec => JSON.parse(readFileSync(path, 'utf8'))

function main() {
  const pinned = join(PINNED, 'spec.json')
  if (!existsSync(pinned)) {
    throw new Error(
      `${pinned} is missing. Run bun install, and pin @appwrite.io/console 19.0.0 or later: earlier releases do not ship their spec.`,
    )
  }

  cli(['specs', '--version=latest', '--mode=normal', '--git=no'])

  const base = readSpec(pinned)
  const branch = readSpec(join(SPECS, 'open-api3-latest-console.json'))
  const preview = mergeSpecs(base, branch)
  writeFileSync(
    join(SPECS, `open-api3-${VERSION}-console.json`),
    JSON.stringify(preview, null, 4),
  )

  console.log(`Pinned SDK: ${count(base)}`)
  console.log(`This branch: ${count(branch)}`)
  console.log(`Preview: ${count(preview)}`)

  cli([
    'sdks',
    '--platform=console',
    '--sdk=web',
    `--version=${VERSION}`,
    '--git=no',
  ])
  run(['npm', 'install', '--no-audit', '--no-fund'], SDK)
  run(['npm', 'run', 'build'], SDK)

  if (process.argv.includes('--no-install')) {
    console.log(`Preview SDK built in ${SDK}`)
    return
  }

  // Keep the pinned spec.json, so the next run merges onto the release again.
  for (const entry of ['dist', 'types', 'package.json']) {
    rmSync(join(PINNED, entry), { recursive: true, force: true })
    cpSync(join(SDK, entry), join(PINNED, entry), { recursive: true })
  }
  rmSync(join(ROOT, 'node_modules', '.vite'), { recursive: true, force: true })

  console.log(
    'Preview SDK installed. Run bun install --force to restore the pinned SDK.',
  )
}

if (import.meta.main) {
  main()
}
