import type { createContainer } from 'almostnode'

export type CliShellContainer = ReturnType<typeof createContainer>

export type CliShellLine =
  | { type: 'command'; text: string }
  | { type: 'stdout'; text: string }
  | { type: 'stderr'; text: string }
  | { type: 'system'; text: string }

export type CliShellBootstrapConfig = {
  projectId: string
  projectEndpoint: string
  consoleEndpoint: string
  email: string
  sessionCookie: string
}

export type CliShellStatus =
  | 'idle'
  | 'bootstrapping'
  | 'ready'
  | 'running'
  | 'error'
