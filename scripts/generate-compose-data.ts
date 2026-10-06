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
import { load as loadYaml } from 'js-yaml'

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
// profile. The separate list is read off the parsed profiles for the same reason,
// so a service added to the profile upstream cannot drift out of this list.
const COMBINED_TOPOLOGY_SERVICES = [
  'appwrite-worker',
  'appwrite-task-scheduler',
]
const SEPARATE_TOPOLOGY_PROFILE = 'separate'
// Profiles the installer leaves on a service. Generator.php only acts on the
// topology profile, so a service under any of these keeps its `profiles:` list
// in the generated compose and stays opt-in through COMPOSE_PROFILES. The
// embeddings container is the one case today: resource-heavy, so it is not
// started until an operator adds "embedding" to COMPOSE_PROFILES.
const KEPT_PROFILES = ['embedding']
// Every Compose profile the appwrite compose file is allowed to use. Any other
// profile aborts generation, because the docs generator would not know which
// topology or database option it belongs to.
const KNOWN_PROFILES = [SEPARATE_TOPOLOGY_PROFILE, ...KEPT_PROFILES]
const DATABASE_SERVICES = ['postgresql', 'mariadb', 'mongodb']
const AUTOGRAVITY_SERVICE = 'appwrite-autogravity'

// The appwrite repo's .env is a development file. These keys are dropped from the
// .env the docs hand to a self-hoster:
//   - COMPOSE_PROFILES holds a development selection upstream. The installer
//     does not write the key either; a self-hoster who wants an opt-in profile
//     such as "embedding" adds it to their own .env.
//   - The DocumentsDB and VectorsDB keys point at engines a self-hosted install
//     does not deploy. Both products ship disabled, so the keys have no effect.
//   - VITE_GROWTH_ENDPOINT pointed the console at the retired growth server.
//     The console now sends support and feedback to Appwrite Cloud directly.
const OMITTED_ENV_KEYS = [
  'COMPOSE_PROFILES',
  'VITE_GROWTH_ENDPOINT',
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
 * Removes the OMITTED_ENV_KEYS entries, pass-through or with a value, from a
 * service's `environment:` list. Compose passes an unset key through as unset,
 * and nothing reads the omitted keys, so dropping them changes no behaviour.
 */
function stripOmittedServiceEnv(block: string): string {
  const omitted = new Set(OMITTED_ENV_KEYS)
  return block
    .split('\n')
    .filter((line) => {
      const match = line.match(/^      - ([A-Z0-9_]+)(=.*)?$/)
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

type ServiceDefinition = Record<string, unknown>

/** Compose profiles of a parsed service definition. */
function profilesOf(service: ServiceDefinition): string[] {
  const profiles = service.profiles
  return Array.isArray(profiles) ? profiles.map(String) : []
}

/** Name of the service a parsed service definition extends, if any. */
function extendsOf(service: ServiceDefinition): string | null {
  const ext = service.extends
  if (typeof ext === 'string') return ext
  if (ext && typeof ext === 'object' && 'service' in ext) {
    return String((ext as { service: unknown }).service)
  }
  return null
}

/**
 * Inlines a service that `extends` one of the combined containers, the way the
 * PHP generator does with array_replace_recursive() before it drops the base
 * service from a separate-topology compose. The text of the base block is kept
 * and each top-level key the child sets replaces the same key in the base.
 *
 * Only scalar overrides are supported. A child that overrides a mapping would
 * need a deep merge, and the generator aborts rather than guess.
 */
function resolveExtends(
  child: Block,
  childDef: ServiceDefinition,
  base: Block,
  baseDef: ServiceDefinition,
): string {
  const childKeys = splitBlocks(
    child.block.split('\n').slice(1).join('\n'),
    '    ',
    true,
  ).filter((k) => k.name !== 'extends' && k.name !== 'profiles')
  const baseKeys = splitBlocks(
    base.block.split('\n').slice(1).join('\n'),
    '    ',
    true,
  )
  for (const key of childKeys) {
    const childValue = childDef[key.name]
    const baseValue = baseDef[key.name]
    if (
      (childValue !== null && typeof childValue === 'object') ||
      (baseValue !== null && typeof baseValue === 'object')
    ) {
      throw new Error(
        `Service "${child.name}" overrides "${key.name}" of "${base.name}" with a non-scalar value; add a deep merge before regenerating`,
      )
    }
  }
  const merged = baseKeys.map(
    (k) => childKeys.find((c) => c.name === k.name) ?? k,
  )
  for (const key of childKeys) {
    if (!baseKeys.some((k) => k.name === key.name)) merged.push(key)
  }
  return [`  ${child.name}:`, ...merged.map((k) => k.block)].join('\n')
}

/**
 * Removes the topology `profiles:` list from a service block. A service whose
 * profiles are all in KEPT_PROFILES keeps the list, mirroring Generator.php,
 * which only rewrites the topology profile.
 */
function stripProfiles(block: string, profiles: string[]): string {
  if (profiles.length > 0 && profiles.every((p) => KEPT_PROFILES.includes(p))) {
    return block
  }
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

  // The text blocks below preserve the upstream file's comments and formatting
  // for the generated output. Structure (profiles, extends) is read from the
  // parsed document so a formatting change upstream cannot misclassify a service.
  const parsed = loadYaml(compose) as { services?: Record<string, unknown> }
  const definitions = new Map<string, ServiceDefinition>(
    Object.entries(parsed.services ?? {}).map(([name, def]) => [
      name,
      (def ?? {}) as ServiceDefinition,
    ]),
  )

  const rawServices = splitBlocks(servicesSection, '  ')
  const rawByName = new Map(rawServices.map((s) => [s.name, s]))
  if (rawServices.length !== definitions.size) {
    throw new Error(
      `Text split found ${rawServices.length} services but the YAML parser found ${definitions.size}`,
    )
  }
  for (const s of rawServices) {
    if (!definitions.has(s.name)) {
      throw new Error(
        `Service "${s.name}" split from text is not in the parsed YAML`,
      )
    }
  }

  const definitionOf = (name: string): ServiceDefinition => {
    const def = definitions.get(name)
    if (def === undefined)
      throw new Error(`Service "${name}" not found in docker-compose.yml`)
    return def
  }

  for (const [name, def] of definitions) {
    for (const profile of profilesOf(def)) {
      if (!KNOWN_PROFILES.includes(profile)) {
        throw new Error(
          `Service "${name}" uses unknown Compose profile "${profile}"`,
        )
      }
    }
  }

  const separateServices = rawServices
    .filter((s) =>
      profilesOf(definitionOf(s.name)).includes(SEPARATE_TOPOLOGY_PROFILE),
    )
    .map((s) => s.name)
  const TOPOLOGY_SERVICES: Record<string, string[]> = {
    combined: COMBINED_TOPOLOGY_SERVICES,
    separate: separateServices,
  }

  if (separateServices.length === 0) {
    throw new Error(
      `No service carries the "${SEPARATE_TOPOLOGY_PROFILE}" Compose profile`,
    )
  }

  const services = rawServices.map((s) => {
    const def = definitionOf(s.name)
    const base = extendsOf(def)
    let block = s.block
    if (base !== null) {
      // A separate-topology compose drops the combined containers, so a child
      // that extends one of them must carry the full definition itself.
      if (!COMBINED_TOPOLOGY_SERVICES.includes(base)) {
        throw new Error(
          `Service "${s.name}" extends "${base}", which is not a combined topology service`,
        )
      }
      const baseBlock = rawByName.get(base)
      if (baseBlock === undefined) {
        throw new Error(`Service "${s.name}" extends missing service "${base}"`)
      }
      block = resolveExtends(s, def, baseBlock, definitionOf(base))
    }
    return {
      name: s.name,
      block: pinImage(
        stripOmittedServiceEnv(stripProfiles(block, profilesOf(def))),
      ),
    }
  })
  const volumes = splitBlocks(volumesSection, '  ', true)

  const knownNames = new Set(services.map((s) => s.name))
  const expected = [
    ...DATABASE_SERVICES,
    ...TOPOLOGY_SERVICES.combined,
    AUTOGRAVITY_SERVICE,
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

export const AUTOGRAVITY_SERVICE = ${JSON.stringify(AUTOGRAVITY_SERVICE)}

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
