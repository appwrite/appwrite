import type { WasmCliContainer } from './wasm/runtime'

export type CliShellContainer = WasmCliContainer

export type CliShellLine =
  | { type: 'command'; text: string }
  | { type: 'stdout'; text: string }
  | { type: 'stderr'; text: string }
  | { type: 'system'; text: string }
  | { type: 'rich'; text: string }
  | { type: 'suggestions'; commands: readonly string[] }

export type CliShellRuntimeConfig = {
  projectId: string
  projectEndpoint: string
  consoleEndpoint: string
  /** Organization (team) ID; persisted in appwrite.config.json for the CLI. */
  organizationId?: string
}

export type CliShellStatus =
  | 'idle'
  | 'bootstrapping'
  | 'ready'
  | 'running'
  | 'error'

export type CliShellRunOptions = {
  cwd?: string
  onStdout?: (chunk: string) => void
  onStderr?: (chunk: string) => void
  signal?: AbortSignal
}
