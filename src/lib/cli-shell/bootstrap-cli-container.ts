import type { CliShellBootstrapConfig, CliShellContainer } from './types'
import {
  buildAppwriteConfigJson,
  buildCliPrefsJson,
} from './console-session'

const APPWRITE_CLI_PACKAGE = 'appwrite-cli'

export async function bootstrapCliContainer(
  config: CliShellBootstrapConfig,
): Promise<CliShellContainer> {
  const { createContainer } = await import('almostnode')

  const container = createContainer({
    cwd: '/project',
    env: {
      HOME: '/home/user',
      NODE_ENV: 'development',
      TERM: 'xterm-256color',
      PATH: '/usr/local/bin:/usr/bin:/bin:/node_modules/.bin',
    },
  })

  const { vfs } = container

  vfs.mkdirSync('/project', { recursive: true })
  vfs.mkdirSync('/home/user/.appwrite', { recursive: true })

  vfs.writeFileSync(
    '/project/appwrite.config.json',
    buildAppwriteConfigJson({
      projectId: config.projectId,
      endpoint: config.projectEndpoint,
    }),
  )

  vfs.writeFileSync(
    '/home/user/.appwrite/prefs.json',
    buildCliPrefsJson({
      consoleEndpoint: config.consoleEndpoint,
      email: config.email,
      sessionCookie: config.sessionCookie,
      sessionId: `console-${config.projectId}`,
    }),
  )

  await container.npm.install(APPWRITE_CLI_PACKAGE)

  return container
}
