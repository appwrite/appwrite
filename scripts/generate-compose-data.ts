/**
 * Generates the docs compose-generator data from the sibling appwrite repo.
 * Reads ../appwrite/docker-compose.yml, .env, and the MongoDB helper files,
 * strips Compose profiles, pins the appwrite image version, and writes
 * src/lib/docs/compose-generator/composeData.ts.
 * Run: bun run scripts/generate-compose-data.ts
 */
import { execSync } from 'node:child_process'
import { readFile, writeFile, mkdir } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const VIBES_ROOT = join(__dirname, '..')
// Resolve the sibling appwrite repo from the main checkout, so the script
// also works from git worktrees. Override with APPWRITE_REPO.
const MAIN_CHECKOUT = dirname(
  execSync('git rev-parse --path-format=absolute --git-common-dir', {
    cwd: VIBES_ROOT,
    encoding: 'utf-8',
  }).trim(),
)
const APPWRITE_REPO =
  process.env.APPWRITE_REPO ?? join(MAIN_CHECKOUT, '..', 'appwrite')
const OUTPUT_DIR = join(VIBES_ROOT, 'src', 'lib', 'docs', 'compose-generator')
const OUTPUT_FILE = join(OUTPUT_DIR, 'composeData.ts')

// Mirrors TOPOLOGY_SERVICE_GROUPS in src/Appwrite/Docker/Compose/Generator.php,
// which names the combined services and selects the separate ones by Compose
// profile. The separate list is read off the profiles below for the same reason,
// so a service added to the profile upstream cannot drift out of this list.
const COMBINED_TOPOLOGY_SERVICES = [
  'appwrite-worker',
  'appwrite-task-scheduler',
]
const SEPARATE_TOPOLOGY_PROFILE = 'separate'
const DATABASE_SERVICES = ['postgresql', 'mariadb', 'mongodb']
const ASSISTANT_SERVICE = 'appwrite-assistant'

// The appwrite repo's .env is a development file. These keys are dropped from the
// .env the docs hand to a self-hoster:
//   - COMPOSE_PROFILES selects services by Compose profile, and stripProfiles()
//     removes every profile from the docs compose, so the key does nothing here.
//   - The DocumentsDB and VectorsDB keys point at engines a self-hosted install
//     does not deploy. Both products ship disabled, so the keys have no effect.
const OMITTED_ENV_KEYS = [
  'COMPOSE_PROFILES',
  '_APP_DOCUMENTSDB',
  '_APP_VECTORSDB',
  '_APP_DB_ADAPTER_DOCUMENTSDB',
  '_APP_DB_HOST_DOCUMENTSDB',
  '_APP_DB_PORT_DOCUMENTSDB',
  '_APP_DB_SCHEMA_DOCUMENTSDB',
  '_APP_DB_USER_DOCUMENTSDB',
  '_APP_DB_PASS_DOCUMENTSDB',
  '_APP_DB_ADAPTER_VECTORSDB',
  '_APP_DB_HOST_VECTORSDB',
  '_APP_DB_PORT_VECTORSDB',
  '_APP_DB_SCHEMA_VECTORSDB',
  '_APP_DB_USER_VECTORSDB',
  '_APP_DB_PASS_VECTORSDB',
  '_APP_CONNECTIONS_DATABASE_DOCUMENTSDB',
  '_APP_CONNECTIONS_DATABASE_VECTORSDB',
]

/**
 * Removes the OMITTED_ENV_KEYS pass-through entries from a service's
 * `environment:` list. Compose passes an unset key through as unset, and both
 * products default to disabled, so dropping the entries changes no behaviour.
 */
function stripOmittedServiceEnv(block: string): string {
  const omitted = new Set(OMITTED_ENV_KEYS)
  return block
    .split('\n')
    .filter((line) => {
      const match = line.match(/^      - ([A-Z0-9_]+)$/)
      return match === null || !omitted.has(match[1])
    })
    .join('\n')
}

/**
 * Drops the keys in OMITTED_ENV_KEYS, and the comment lines directly above them,
 * from the .env template.
 */
function filterEnv(env: string): string {
  const omitted = new Set(OMITTED_ENV_KEYS)
  const kept: string[] = []
  let pendingComments: string[] = []
  for (const line of env.split('\n')) {
    if (line.startsWith('#')) {
      pendingComments.push(line)
      continue
    }
    const key = line.split('=', 1)[0]
    if (omitted.has(key)) {
      pendingComments = []
      continue
    }
    kept.push(...pendingComments, line)
    pendingComments = []
  }
  kept.push(...pendingComments)
  return kept.join('\n')
}

async function readRepoFile(relativePath: string): Promise<string> {
  return readFile(join(APPWRITE_REPO, relativePath), 'utf-8')
}

/** Profile names listed under a service's `profiles:` key. */
function readProfiles(block: string): string[] {
  const lines = block.split('\n')
  const profiles: string[] = []
  let reading = false
  for (const line of lines) {
    if (/^    profiles:\s*$/.test(line)) {
      reading = true
      continue
    }
    if (!reading) continue
    const match = line.match(/^      - (.+)$/)
    if (match === null) break
    profiles.push(match[1].trim())
  }
  return profiles
}

function stripProfiles(block: string): string {
  const lines = block.split('\n')
  const result: string[] = []
  let skipping = false
  for (const line of lines) {
    if (/^    profiles:\s*$/.test(line)) {
      skipping = true
      continue
    }
    if (skipping) {
      if (/^      - /.test(line)) continue
      skipping = false
    }
    result.push(line)
  }
  return result.join('\n')
}

type Block = { name: string; block: string }

function splitBlocks(
  section: string,
  indent: string,
  allowInlineValue = false,
): Block[] {
  const lines = section.split('\n')
  const blocks: Block[] = []
  let current: Block | null = null
  const keyPattern = allowInlineValue
    ? new RegExp(`^${indent}([A-Za-z0-9_-]+):(?:\\s.*)?$`)
    : new RegExp(`^${indent}([A-Za-z0-9_-]+):\\s*$`)
  for (const line of lines) {
    const match = line.match(keyPattern)
    if (match) {
      if (current) blocks.push(current)
      current = { name: match[1], block: line }
    } else if (current) {
      current.block += '\n' + line
    }
  }
  if (current) blocks.push(current)
  return blocks.map((b) => ({ ...b, block: b.block.replace(/\n+$/, '') }))
}

async function main() {
  const constants = await readRepoFile('app/init/constants.php')
  const versionMatch = constants.match(/APP_VERSION_STABLE = '([^']+)'/)
  if (!versionMatch) throw new Error('APP_VERSION_STABLE not found')
  const version = versionMatch[1]

  const compose = await readRepoFile('docker-compose.yml')
  const servicesStart = compose.indexOf('\nservices:\n')
  const networksStart = compose.indexOf('\nnetworks:\n')
  const volumesStart = compose.indexOf('\nvolumes:\n')
  if (servicesStart < 0 || networksStart < 0 || volumesStart < 0) {
    throw new Error('Unexpected docker-compose.yml layout')
  }

  const prefix = compose.slice(0, servicesStart + '\nservices:\n'.length - 1)
  const servicesSection = compose.slice(
    servicesStart + '\nservices:\n'.length,
    networksStart,
  )
  const networksSection = compose
    .slice(networksStart + 1, volumesStart)
    .trimEnd()
  const volumesSection = compose.slice(volumesStart + '\nvolumes:\n'.length)

  const pinImage = (text: string) =>
    text.replaceAll(
      '${_APP_IMAGE:-appwrite/appwrite}:${_APP_VERSION:-latest}',
      `appwrite/appwrite:${version}`,
    )

  const rawServices = splitBlocks(servicesSection, '  ')
  const separateServices = rawServices
    .filter((s) => readProfiles(s.block).includes(SEPARATE_TOPOLOGY_PROFILE))
    .map((s) => s.name)
  const TOPOLOGY_SERVICES: Record<string, string[]> = {
    combined: COMBINED_TOPOLOGY_SERVICES,
    separate: separateServices,
  }

  const services = rawServices.map((s) => ({
    name: s.name,
    block: pinImage(stripOmittedServiceEnv(stripProfiles(s.block))),
  }))
  const volumes = splitBlocks(volumesSection, '  ', true)

  if (separateServices.length === 0) {
    throw new Error(
      `No service carries the "${SEPARATE_TOPOLOGY_PROFILE}" Compose profile`,
    )
  }

  const knownNames = new Set(services.map((s) => s.name))
  const expected = [
    ...DATABASE_SERVICES,
    ...TOPOLOGY_SERVICES.combined,
    ASSISTANT_SERVICE,
  ]
  for (const name of expected) {
    if (!knownNames.has(name)) {
      throw new Error(`Service "${name}" not found in docker-compose.yml`)
    }
  }

  const env = filterEnv(await readRepoFile('.env'))
  const mongoInit = await readRepoFile('mongo-init.js')
  const mongoEntrypoint = await readRepoFile('mongo-entrypoint.sh')

  const output = `// Generated by scripts/generate-compose-data.ts from the appwrite repo. Do not edit.
export const APPWRITE_VERSION = ${JSON.stringify(version)}

export const DATABASE_SERVICES = ${JSON.stringify(DATABASE_SERVICES)} as const

export const TOPOLOGY_SERVICES = ${JSON.stringify(TOPOLOGY_SERVICES, null, 2)}

export const ASSISTANT_SERVICE = ${JSON.stringify(ASSISTANT_SERVICE)}

export const DATABASE_VOLUMES: Record<string, string[]> = ${JSON.stringify(
    {
      postgresql: ['appwrite-postgresql'],
      mariadb: ['appwrite-mariadb'],
      mongodb: ['appwrite-mongodb', 'appwrite-mongodb-keyfile'],
    },
    null,
    2,
  )}

export const COMPOSE_PREFIX = ${JSON.stringify(prefix)}

export const COMPOSE_SERVICES: Array<{ name: string; block: string }> = ${JSON.stringify(services, null, 2)}

export const COMPOSE_NETWORKS = ${JSON.stringify(networksSection)}

export const COMPOSE_VOLUMES: Array<{ name: string; block: string }> = ${JSON.stringify(volumes, null, 2)}

export const ENV_TEMPLATE = ${JSON.stringify(env)}

export const MONGO_INIT_JS = ${JSON.stringify(mongoInit)}

export const MONGO_ENTRYPOINT_SH = ${JSON.stringify(mongoEntrypoint)}
`

  await mkdir(OUTPUT_DIR, { recursive: true })
  await writeFile(OUTPUT_FILE, output, 'utf-8')
  console.log(
    `Generated composeData.ts (version ${version}, ${services.length} services, ${volumes.length} volumes)`,
  )
}

await main()
