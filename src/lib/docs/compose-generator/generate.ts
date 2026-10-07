import {
  APPWRITE_VERSION,
  AUTOGRAVITY_SERVICE,
  COMPOSE_NETWORKS,
  COMPOSE_PREFIX,
  COMPOSE_SERVICES,
  COMPOSE_VOLUMES,
  DATABASE_SERVICES,
  DATABASE_VOLUMES,
  ENV_TEMPLATE,
  MONGO_ENTRYPOINT_SH,
  MONGO_INIT_JS,
  TOPOLOGY_SERVICES,
} from './composeData'

export type ComposeDatabase = 'postgresql' | 'mariadb' | 'mongodb'
export type ComposeTopology = 'combined' | 'separate'

export type ComposeOptions = {
  database: ComposeDatabase
  topology: ComposeTopology
  autogravity: boolean
}

const AUTOGRAVITY_HOST = `http://${AUTOGRAVITY_SERVICE}:8080`

const DATABASE_PORTS: Record<ComposeDatabase, string> = {
  postgresql: '5432',
  mariadb: '3306',
  mongodb: '27017',
}

export { APPWRITE_VERSION }

/**
 * Assembles a flat docker-compose.yml for the selected database, worker
 * topology, and AutoGravity choices, mirroring the filtering the
 * Appwrite installer performs (src/Appwrite/Docker/Compose/Generator.php).
 * AutoGravity is not an installer option; the installer always ships the
 * container and leaves _APP_AUTOGRAVITY_HOST empty, so the feature is off.
 * The generator instead drops the container and its depends_on entry when
 * AutoGravity is off, and fills the host when it is on.
 */
export function generateCompose({
  database,
  topology,
  autogravity,
}: ComposeOptions): string {
  const excluded = new Set<string>([
    ...DATABASE_SERVICES.filter((service) => service !== database),
    ...TOPOLOGY_SERVICES[topology === 'combined' ? 'separate' : 'combined'],
  ])
  if (!autogravity) excluded.add(AUTOGRAVITY_SERVICE)

  const excludedVolumes = new Set(
    Object.entries(DATABASE_VOLUMES)
      .filter(([db]) => db !== database)
      .flatMap(([, volumes]) => volumes),
  )

  const services = COMPOSE_SERVICES.filter((s) => !excluded.has(s.name))
    .map((s) =>
      autogravity
        ? s.block
        : s.block.replace(
            new RegExp(`^[ \\t]+- ${AUTOGRAVITY_SERVICE}\\n`, 'm'),
            '',
          ),
    )
    .join('\n')
  const volumes = COMPOSE_VOLUMES.filter((v) => !excludedVolumes.has(v.name))
    .map((v) => v.block)
    .join('\n')

  const compose = `${COMPOSE_PREFIX}\n${services}\n${COMPOSE_NETWORKS}\nvolumes:\n${volumes}\n`
  return compose.replaceAll('${_APP_DB_HOST:-postgresql}', database)
}

export function generateEnv({
  database,
  autogravity,
}: Omit<ComposeOptions, 'topology'> & { topology?: ComposeTopology }): string {
  return (
    ENV_TEMPLATE.replace(/^_APP_DB_ADAPTER=.*$/m, `_APP_DB_ADAPTER=${database}`)
      .replace(/^_APP_DB_HOST=.*$/m, `_APP_DB_HOST=${database}`)
      .replace(/^_APP_DB_PORT=.*$/m, `_APP_DB_PORT=${DATABASE_PORTS[database]}`)
      .replace(
        /^_APP_AUTOGRAVITY_HOST=.*$/m,
        `_APP_AUTOGRAVITY_HOST=${autogravity ? AUTOGRAVITY_HOST : ''}`,
      )
      // The template is Appwrite's development .env, whose mock breach checker
      // is refused once _APP_ENV is production; ship the installer's default.
      .replace(
        /^_APP_PWNED_PASSWORDS_DSN=.*$/m,
        '_APP_PWNED_PASSWORDS_DSN=none://localhost',
      )
  )
}

export type GeneratedFile = {
  filename: string
  content: string
  language: 'yaml' | 'bash' | 'javascript'
}

/** Every file the user needs to place next to docker-compose.yml. */
export function generateFiles(options: ComposeOptions): GeneratedFile[] {
  const files: GeneratedFile[] = [
    {
      filename: 'docker-compose.yml',
      content: generateCompose(options),
      language: 'yaml',
    },
    { filename: '.env', content: generateEnv(options), language: 'bash' },
  ]
  if (options.database === 'mongodb') {
    files.push(
      {
        filename: 'mongo-init.js',
        content: MONGO_INIT_JS,
        language: 'javascript',
      },
      {
        filename: 'mongo-entrypoint.sh',
        content: MONGO_ENTRYPOINT_SH,
        language: 'bash',
      },
    )
  }
  return files
}
