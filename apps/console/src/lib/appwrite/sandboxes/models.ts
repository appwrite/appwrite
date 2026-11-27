/**
 * Sandboxes response models, copied from `Sandbox`, `Sandbox\Execution`,
 * `Sandbox\File`, `Sandbox\Process` and `Sandbox\Output` in Cloud's
 * `src/Appwrite/Cloud/Response/Model`. Delete with `service.ts` once the
 * generated SDK carries the `sandboxes` service.
 */

export type SandboxStatus = 'creating' | 'ready' | 'failed' | 'deleting'

/** Image keys Cloud accepts (`app/config/sandboxes.php`). */
export type SandboxImage = 'node-22' | 'python-3.12' | 'ubuntu-24.04'

export type Sandbox = {
  /** Sandbox ID. */
  $id: string
  /** Sandbox creation date in ISO 8601 format. */
  $createdAt: string
  /** Sandbox update date in ISO 8601 format. */
  $updatedAt: string
  /** Image the sandbox runs. */
  image: string
  /** CPU and memory size of the sandbox. */
  specification: string
  /** Sandbox status. */
  status: SandboxStatus
  /**
   * URL the sandbox serves its own API on. Anyone holding it can run commands
   * in the sandbox, so the Console never shows it.
   */
  url: string
  /** Maximum duration of one request to the sandbox URL, in seconds. 0 means no limit. */
  timeout: number
  /** Seconds without traffic after which the sandbox is deleted. */
  idleTimeout: number
}

export type SandboxList = {
  total: number
  sandboxes: Sandbox[]
}

export type SandboxExecution = {
  /** Exit code of the command. 124 when the timeout killed it. */
  exitCode: number
  stdout: string
  stderr: string
  /** Whether stdout or stderr went past 1 MiB and was cut. */
  truncated: boolean
  /** How long the command ran, in seconds. */
  duration: number
}

export type SandboxFile = {
  name: string
  type: 'file' | 'directory'
  /** File size in bytes. */
  size: number
}

export type SandboxFileList = {
  total: number
  files: SandboxFile[]
}

export type SandboxProcess = {
  /** Process ID, unique within its sandbox. */
  $id: string
  /** Command the process runs. Empty for a terminal running an interactive shell. */
  command: string
  /** Whether the process runs on a terminal. */
  pty: boolean
  running: boolean
  /** Exit code once the process has exited. Null while it runs. */
  exitCode: number | null
  /** Terminal width in columns. 0 without a terminal. */
  cols: number
  /** Terminal height in rows. 0 without a terminal. */
  rows: number
  startedAt: string
  endedAt: string | null
}

export type SandboxProcessList = {
  total: number
  processes: SandboxProcess[]
}

export type SandboxOutput = {
  /** Output written since `offset`. For a terminal, the raw screen output. */
  data: string
  /** Byte offset `data` starts at. Later than asked when that output is no longer kept. */
  offset: number
  /** Byte offset to read from next. */
  nextOffset: number
  running: boolean
  exitCode: number | null
}
