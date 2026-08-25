import {
  APPWRITE_VERSION,
  ASSISTANT_SERVICE,
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
  assistant: boolean
}

const DATABASE_PORTS: Record<ComposeDatabase, string> = {
  postgresql: '5432',
  mariadb: '3306',
  mongodb: '27017',
}

export { APPWRITE_VERSION }

/**
 * Assembles a flat docker-compose.yml for the selected database, worker
 * topology, and assistant choice, mirroring the filtering the Appwrite
 * installer performs (src/Appwrite/Docker/Compose/Generator.php).
 */
export function generateCompose({
  database,
  topology,
  assistant,
}: ComposeOptions): string {
  const excluded = new Set<string>([
    ...DATABASE_SERVICES.filter((service) => service !== database),
    ...TOPOLOGY_SERVICES[topology === 'combined' ? 'separate' : 'combined'],
  ])
  if (!assistant) excluded.add(ASSISTANT_SERVICE)

  const excludedVolumes = new Set(
    Object.entries(DATABASE_VOLUMES)
      .filter(([db]) => db !== database)
      .flatMap(([, volumes]) => volumes),
  )

  const services = COMPOSE_SERVICES.filter((s) => !excluded.has(s.name))
    .map((s) => s.block)
    .join('\n')
  const volumes = COMPOSE_VOLUMES.filter((v) => !excludedVolumes.has(v.name))
    .map((v) => v.block)
    .join('\n')

  const compose = `${COMPOSE_PREFIX}\n${services}\n${COMPOSE_NETWORKS}\nvolumes:\n${volumes}\n`
  return compose.replaceAll('${_APP_DB_HOST:-postgresql}', database)
}

export function generateEnv({
  database,
  assistant,
}: Omit<ComposeOptions, 'topology'> & { topology?: ComposeTopology }): string {
  return ENV_TEMPLATE.replace(
    /^_APP_DB_ADAPTER=.*$/m,
    `_APP_DB_ADAPTER=${database}`,
  )
    .replace(/^_APP_DB_HOST=.*$/m, `_APP_DB_HOST=${database}`)
    .replace(/^_APP_DB_PORT=.*$/m, `_APP_DB_PORT=${DATABASE_PORTS[database]}`)
    .replace(
      /^_APP_ASSISTANT_OPENAI_API_KEY=.*$/m,
      `_APP_ASSISTANT_OPENAI_API_KEY=${assistant ? 'your-openai-api-key' : ''}`,
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
