/**
 * Build a preview Console SDK: this branch's console spec laid over the spec the
 * pinned @appwrite.io/console was generated from (its `spec.json`). Cloud-only
 * services stay and this branch's new or changed endpoints appear, so Console
 * code for an unreleased endpoint type-checks and builds.
 *
 * Objects merge key by key and this branch wins on values both define. Lists merge
 * by identity (a parameter's name and location, a $ref, a tag or enum value's name),
 * so Cloud-only parameters, properties and enum values survive. Removals and
 * renames are not carried.
 *
 * Needs PHP with swoole and `composer install` at the repository root.
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

export const merge = (base: unknown, overlay: unknown): unknown => {
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

export const mergeSpecs = (base: Spec, overlay: Spec): Spec => ({
  ...(merge(base, overlay) as Spec),
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

const readSpec = (path: string): Spec => JSON.parse(readFileSync(path, 'utf8'))

function main() {
  const pinned = join(PINNED, 'spec.json')
  if (!existsSync(pinned)) {
    throw new Error(
      `${pinned} is missing. Run bun install, and pin @appwrite.io/console 19.0.0 or later: earlier releases do not ship their spec.`,
    )
  }

  run(
    [
      'php',
      'app/cli.php',
      'specs',
      '--version=latest',
      '--mode=normal',
      '--git=no',
    ],
    REPOSITORY,
  )

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

  run(
    [
      'php',
      'app/cli.php',
      'sdks',
      '--platform=console',
      '--sdk=web',
      `--version=${VERSION}`,
      '--git=no',
    ],
    REPOSITORY,
  )
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
